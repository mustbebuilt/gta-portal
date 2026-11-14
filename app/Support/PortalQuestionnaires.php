<?php

namespace App\Support;

use App\Models\Apprentice;
use App\Models\PortalQuestionnaireAssignment as Assignment;
use App\Models\PortalQuestionnaireForm as Form;
use App\Models\PortalQuestionnaireSubmission as Submission;
use App\Models\SupportConcern;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/** Native questionnaires. External responder links and manual receipts stay separate. */
class PortalQuestionnaires
{
    public const STATES = ['Assigned', 'In progress', 'Submitted', 'Checked', 'Cancelled'];

    public static function author(User $u): bool
    {
        return $u->active && (LearningAccess::manager($u) || SupportAccess::lead($u));
    }

    public static function learner(User $u, Apprentice $a): bool
    {
        return $u->active && $u->role === 'apprentice' && $a->user_id === $u->id && Apprentice::where('user_id', $u->id)->count() === 1;
    }

    public static function visible(User $u, Assignment $x): bool
    {
        $a = Apprentice::findOrFail($x->apprentice_id);

        return SupportAccess::team($u, $a) || (self::learner($u, $a) && $x->recipient_id === $u->id);
    }

    public static function queue(User $u)
    {
        if ($u->role === 'apprentice') {
            abort_unless($u->active && Apprentice::where('user_id', $u->id)->count() === 1, 403);

            return Assignment::where('apprentice_id', Apprentice::where('user_id', $u->id)->value('id'))->where('recipient_id', $u->id);
        }
        abort_unless(SupportAccess::team($u), 403);

        return Assignment::whereIn('apprentice_id', SupportAccess::ids($u));
    }

    private static function fail(string $s): never
    {
        throw ValidationException::withMessages(['version' => $s]);
    }

    private static function actor(User $u, int $auth): User
    {
        $fresh = User::whereKey($u->id)->lockForUpdate()->firstOrFail();
        abort_unless($fresh->active && $fresh->auth_version === $auth, 403);

        return $fresh;
    }

    private static function lockedForm(string $id): Form
    {
        $source = Form::findOrFail($id);
        Form::where('series_id', $source->series_id)->where('revision', 1)->lockForUpdate()->firstOrFail();

        return Form::whereKey($id)->lockForUpdate()->firstOrFail();
    }

    private static function revision($row, int $v): void
    {
        if ($row->version !== $v) {
            self::fail('This record changed. Reopen the current page before saving.');
        }
    }

    public static function definition(array $d): array
    {
        $questions = [];
        $keys = [];
        if (count($d['questions']) < 1 || count($d['questions']) > 30) {
            self::fail('Use between one and thirty questions.');
        }
        foreach ($d['questions'] as $q) {
            if (! is_array($q) || ! is_string($q['key'] ?? null) || ! preg_match('/^[a-z][a-z0-9_]{0,39}$/D', $q['key']) || in_array($q['key'], $keys, true) || ! is_string($q['label'] ?? null) || ! filled($q['label']) || mb_strlen($q['label']) > 500 || ! in_array($q['type'] ?? null, ['text', 'choice'], true) || ! is_bool($q['required'] ?? null)) {
                self::fail('Check unique question keys, labels, types and required flags.');
            }
            $opts = $q['options'] ?? [];
            if (! is_array($opts) || ! array_is_list($opts) || ($q['type'] === 'text' && $opts !== []) || ($q['type'] === 'choice' && (count($opts) < 2 || count($opts) > 12))) {
                self::fail('Text questions have no options; choices need two to twelve options.');
            }
            foreach ($opts as $o) {
                if (! is_string($o) || ! filled($o) || mb_strlen($o) > 180) {
                    self::fail('Each choice needs a nonblank label of at most 180 characters.');
                }
            }
            if (count(array_unique($opts)) !== count($opts)) {
                self::fail('Choice options must be unique.');
            }
            $keys[] = $q['key'];
            $questions[] = ['key' => $q['key'], 'label' => trim($q['label']), 'type' => $q['type'], 'required' => $q['required'], 'options' => $opts];
        }
        // Ongoing wellbeing is voluntary: it cannot acquire mandatory answers or a due date.
        if ($d['kind'] === 'ongoing' && collect($questions)->contains('required', true)) {
            self::fail('Ongoing wellbeing is voluntary. Set all its questions to optional.');
        }

        return ['kind' => $d['kind'], 'title' => trim($d['title']), 'introduction' => trim($d['introduction']), 'privacy_notice' => trim($d['privacy_notice']), 'questions' => $questions];
    }

    public static function content(Form $f): array
    {
        return $f->only('series_id', 'revision', 'kind', 'title', 'introduction', 'privacy_notice', 'questions');
    }

    public static function intact(Form $f): bool
    {
        return is_string($f->content_hash) && hash_equals($f->content_hash, ReviewedImports::hash(self::content($f)));
    }

    public static function saveForm(User $u, int $auth, ?Form $form, array $data): Form
    {
        return DB::transaction(function () use ($u, $auth, $form, $data) {
            $f = $form ? self::lockedForm($form->id) : new Form(['id' => (string) Str::uuid(), 'series_id' => (string) Str::uuid(), 'revision' => 1]);
            $actor = self::actor($u, $auth);
            abort_unless(self::author($actor), 403);
            $d = self::definition($data);
            if ($f->exists) {
                self::revision($f, (int) $data['version']);
                if ($f->state !== 'Draft') {
                    self::fail('Published forms are frozen. Create a new revision.');
                }$f->version++;
            }
            $f->fill($d);
            $f->authored_by = $actor->id;
            $f->save();
            self::event($actor, $f, null, 'form_saved', $f->version);

            return $f;
        });
    }

    public static function formAction(User $u, int $auth, Form $form, int $v, string $action): Form
    {
        return DB::transaction(function () use ($u, $auth, $form, $v, $action) {
            $f = self::lockedForm($form->id);
            $actor = self::actor($u, $auth);
            abort_unless(self::author($actor), 403);
            self::revision($f, $v);
            if ($action === 'revise') {
                if ($f->state === 'Draft' || ! self::intact($f)) {
                    self::fail('Use an intact published or retired source to create a revision.');
                }
                // All revisions serialize against the same first row, including branches from old revisions.
                Form::where('series_id', $f->series_id)->where('revision', 1)->lockForUpdate()->firstOrFail();
                if (Form::where('series_id', $f->series_id)->where('state', 'Draft')->exists()) {
                    self::fail('A draft revision already exists for this form.');
                }
                $next = new Form([...self::content($f), 'id' => (string) Str::uuid(), 'revision' => (int) Form::where('series_id', $f->series_id)->max('revision') + 1, 'authored_by' => $actor->id]);
                $next->save();
                self::event($actor, $next, null, 'revision_created', 1);

                return $next;
            }
            abort_unless(LearningAccess::manager($actor), 403);
            if ($action === 'publish') {
                if ($f->state !== 'Draft' || $f->authored_by === $actor->id) {
                    self::fail('A different administrator or CEO must check and publish this draft.');
                }
                self::definition($f->toArray());
                $f->state = 'Published';
                $f->content_hash = ReviewedImports::hash(self::content($f));
                $f->approved_by = $actor->id;
                $f->published_at = now();
            } elseif ($action === 'retire') {
                if ($f->state !== 'Published' || ! self::intact($f)) {
                    self::fail('Only an intact published revision can be retired.');
                }$f->state = 'Retired';
            } else {
                abort(422);
            }
            $f->version++;
            $f->save();
            self::event($actor, $f, null, 'form_'.$action, $f->version);

            return $f;
        });
    }

    public static function assign(User $u, int $auth, Form $form, array $data): array
    {
        return DB::transaction(function () use ($u, $auth, $form, $data) {
            $f = self::lockedForm($form->id);
            self::revision($f, (int) $data['version']);
            $ids = $data['apprentice_ids'];
            sort($ids, SORT_NUMERIC);
            $profiles = Apprentice::whereIn('id', $ids)->orderBy('id')->lockForUpdate()->get()->keyBy('id');
            if ($profiles->count() !== count($ids)) {
                self::fail('One of the selected profiles is unavailable.');
            }
            $actor = self::actor($u, $auth);
            abort_unless(SupportAccess::team($actor), 403);
            if ($f->state !== 'Published' || ! self::intact($f)) {
                self::fail('Use an intact current published form revision.');
            }
            if ($f->kind === 'ongoing' && filled($data['due_date'] ?? null)) {
                self::fail('Ongoing wellbeing is voluntary and has no due or overdue flag.');
            }
            $made = [];
            $existing = [];
            foreach ($ids as $id) {
                $a = $profiles->get($id);
                abort_unless(SupportAccess::team($actor, $a), 403);
                $recipient = User::whereKey($a->user_id)->lockForUpdate()->first();
                if (! $recipient || ! self::learner($recipient, $a) || ! $recipient->email_verified_at) {
                    self::fail('Each selected apprentice needs one active linked apprentice account with a verified email. No assignments were created.');
                }
                // Also prevent revision duplicates for the same series and cycle.
                if (Assignment::where('apprentice_id', $a->id)->where('cycle_code', $data['cycle_code'])->whereHas('form', fn ($q) => $q->where('series_id', $f->series_id))->exists()) {
                    $existing[] = $a->id;

                    continue;
                }
                $x = Assignment::create(['id' => (string) Str::uuid(), 'form_id' => $f->id, 'apprentice_id' => $a->id, 'recipient_id' => $recipient->id, 'assigned_by' => $actor->id, 'cycle_code' => $data['cycle_code'], 'due_date' => $data['due_date'] ?? null]);
                self::event($actor, $f, $x, 'assigned', $x->version);
                $made[] = $x->id;
            }

            return ['created' => $made, 'existing_apprentices' => $existing];
        });
    }

    private static function answers(Form $f, array $answers, bool $submit): array
    {
        if (count($answers) > 30 || array_diff(array_keys($answers), array_column($f->questions, 'key'))) {
            self::fail('Answers contain an unknown question. Reopen the assigned form.');
        }
        $clean = [];
        foreach ($f->questions as $q) {
            $value = $answers[$q['key']] ?? '';
            if (! is_string($value) || mb_strlen($value) > 5000 || str_contains($value, "\0")) {
                self::fail('Use text answers of up to 5,000 characters per question.');
            }
            $value = trim($value);
            if ($submit && $q['required'] && $value === '') {
                self::fail('Answer each required question before submitting.');
            }
            if ($q['type'] === 'choice' && $value !== '' && ! in_array($value, $q['options'], true)) {
                self::fail('Choose one of the saved options.');
            }$clean[$q['key']] = $value;
        }

        return $clean;
    }

    private static function bound(User $u, Assignment $x, Apprentice $a): void
    {
        abort_unless($x->recipient_id === $u->id && self::learner($u, $a), 403);
    }

    private static function digest(array $payload): string
    {
        return hash_hmac('sha256', json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR), (string) config('app.key'));
    }

    public static function submissionIntact(Submission $s, Assignment $x, Form $f): bool
    {
        try {
            $p = $s->answer_payload;

            return self::intact($f) && $p['assignment_id'] === $x->id && $p['recipient_id'] === $x->recipient_id && $p['apprentice_id'] === $x->apprentice_id && $p['cycle_code'] === $x->cycle_code && $p['form_hash'] === $f->content_hash && $p['revision'] === $s->revision && $p['submitted_at'] === $s->submitted_at->toIso8601String() && hash_equals($s->payload_hash, self::digest($p));
        } catch (\Throwable) {
            return false;
        }
    }

    private static function draftPayload(Assignment $x, Form $f, array $answers): array
    {
        return ['assignment_id' => $x->id, 'apprentice_id' => $x->apprentice_id, 'recipient_id' => $x->recipient_id, 'form_hash' => $f->content_hash, 'cycle_code' => $x->cycle_code, 'answers' => $answers];
    }

    public static function draftAnswers(Assignment $x, Form $f): ?array
    {
        $payload = $x->draft_answers;
        if ($payload === null) {
            return null;
        }
        if (! is_array($payload) || ($payload['assignment_id'] ?? null) !== $x->id || ($payload['apprentice_id'] ?? null) !== $x->apprentice_id || ($payload['recipient_id'] ?? null) !== $x->recipient_id || ($payload['form_hash'] ?? null) !== $f->content_hash || ($payload['cycle_code'] ?? null) !== $x->cycle_code || ! is_array($payload['answers'] ?? null)) {
            self::fail('The saved draft no longer matches this assignment. Ask GTA to check the source.');
        }

        return $payload['answers'];
    }

    public static function respond(User $u, int $auth, Assignment $assignment, array $data, bool $submit): void
    {
        DB::transaction(function () use ($u, $auth, $assignment, $data, $submit): void {
            $f = self::lockedForm($assignment->form_id);
            $a = Apprentice::whereKey($assignment->apprentice_id)->lockForUpdate()->firstOrFail();
            $actor = self::actor($u, $auth);
            $x = Assignment::whereKey($assignment->id)->lockForUpdate()->firstOrFail();
            self::bound($actor, $x, $a);
            self::revision($x, (int) $data['version']);
            if (! in_array($x->state, ['Assigned', 'In progress'], true) || ! in_array($f->state, ['Published', 'Retired'], true) || ! self::intact($f)) {
                self::fail('This assignment is no longer editable or its form needs checking.');
            }
            $answers = self::answers($f, $data['answers'] ?? [], $submit);
            if ($submit) {
                if (! ($data['confirm'] ?? false)) {
                    self::fail('Confirm that you have read the privacy notice and want to submit.');
                }
                $x->submission_count++;
                $at = now()->startOfSecond();
                $p = ['assignment_id' => $x->id, 'recipient_id' => $actor->id, 'apprentice_id' => $x->apprentice_id, 'cycle_code' => $x->cycle_code, 'form_hash' => $f->content_hash, 'revision' => $x->submission_count, 'submitted_at' => $at->toIso8601String(), 'answers' => $answers];
                Submission::create(['id' => (string) Str::uuid(), 'assignment_id' => $x->id, 'revision' => $x->submission_count, 'answer_payload' => $p, 'payload_hash' => self::digest($p), 'submitted_at' => $at]);
                $x->state = 'Submitted';
                $x->submitted_at = $at;
                $x->draft_answers = null;
            } else {
                $x->draft_answers = self::draftPayload($x, $f, $answers);
                $x->state = 'In progress';
            }
            $x->version++;
            $x->save();
            self::event($actor, $f, $x, $submit ? 'submitted' : 'draft_saved', $x->version);
        });
    }

    public static function review(User $u, int $auth, Assignment $assignment, array $data): void
    {
        DB::transaction(function () use ($u, $auth, $assignment, $data): void {
            $f = self::lockedForm($assignment->form_id);
            $a = Apprentice::whereKey($assignment->apprentice_id)->lockForUpdate()->firstOrFail();
            $actor = self::actor($u, $auth);
            abort_unless(SupportAccess::team($actor, $a), 403);
            $x = Assignment::whereKey($assignment->id)->lockForUpdate()->firstOrFail();
            self::revision($x, (int) $data['version']);
            $s = Submission::where('assignment_id', $x->id)->where('revision', $x->submission_count)->lockForUpdate()->first();
            if ($x->state !== 'Submitted' || ! $s || $s->state !== 'Submitted' || ! self::submissionIntact($s, $x, $f)) {
                self::fail('Use the current intact submitted response; drafts and old revisions cannot be checked.');
            }
            $action = $data['action'];
            $s->checked_by = $actor->id;
            $s->checked_at = now();
            $s->private_note = $data['private_note'] ?? null;
            $s->shared_note = $data['shared_note'] ?? null;
            if ($action === 'return') {
                if (! filled($s->shared_note)) {
                    self::fail('Explain to the apprentice what to check before resubmitting.');
                }
                $s->state = 'Returned';
                $x->state = 'In progress';
                $x->draft_answers = self::draftPayload($x, $f, $s->answer_payload['answers']);
            } elseif ($action === 'check') {
                if (! in_array($data['followup'] ?? null, ['Required', 'Not required'], true)) {
                    self::fail('Record whether follow-up is required.');
                }
                if ($data['followup'] === 'Not required' && filled($data['concern_id'] ?? null)) {
                    self::fail('A concern link requires follow-up.');
                }
                if (filled($data['concern_id'] ?? null)) {
                    $c = SupportConcern::whereKey($data['concern_id'])->lockForUpdate()->first();
                    if (! $c || $c->apprentice_id !== $a->id) {
                        self::fail('Link only a support concern belonging to this apprentice.');
                    }$s->concern_id = $c->id;
                }
                $s->state = 'Checked';
                $s->followup = $data['followup'];
                $x->state = 'Checked';
            } else {
                abort(422);
            }
            $s->version++;
            $s->save();
            $x->version++;
            $x->save();
            self::event($actor, $f, $x, $action === 'return' ? 'returned_for_changes' : 'inclusion_checked', $x->version);
        });
    }

    public static function cancel(User $u, int $auth, Assignment $assignment, int $version, string $reason): void
    {
        DB::transaction(function () use ($u, $auth, $assignment, $version, $reason): void {
            self::lockedForm($assignment->form_id);
            $a = Apprentice::whereKey($assignment->apprentice_id)->lockForUpdate()->firstOrFail();
            $actor = self::actor($u, $auth);
            abort_unless(SupportAccess::team($actor, $a), 403);
            $x = Assignment::whereKey($assignment->id)->lockForUpdate()->firstOrFail();
            self::revision($x, $version);
            if (! in_array($x->state, ['Assigned', 'In progress'], true)) {
                self::fail('Only unsubmitted or returned assignments can be cancelled. Submitted history stays available for checking.');
            }
            $x->state = 'Cancelled';
            $x->version++;
            $x->save();
            self::event($actor, $x->form, $x, 'cancelled', $x->version, $reason);
        });
    }

    public static function followup(User $u, int $auth, Assignment $assignment, array $data): void
    {
        DB::transaction(function () use ($u, $auth, $assignment, $data): void {
            $f = self::lockedForm($assignment->form_id);
            $a = Apprentice::whereKey($assignment->apprentice_id)->lockForUpdate()->firstOrFail();
            $actor = self::actor($u, $auth);
            abort_unless(SupportAccess::team($actor, $a), 403);
            $x = Assignment::whereKey($assignment->id)->lockForUpdate()->firstOrFail();
            self::revision($x, (int) $data['version']);
            $s = Submission::where('assignment_id', $x->id)->where('revision', $x->submission_count)->lockForUpdate()->first();
            if ($x->state !== 'Checked' || ! $s || $s->state !== 'Checked' || $s->followup !== 'Required' || ! self::submissionIntact($s, $x, $f)) {
                self::fail('Use a current intact checked response with follow-up required.');
            }
            $resolve = $data['action'] === 'resolve';
            if (($resolve && $s->followup_resolved_at) || (! $resolve && ! $s->followup_resolved_at)) {
                self::fail('The follow-up status already changed. Reopen the current page.');
            }
            if ($resolve && $s->concern_id) {
                $concern = SupportConcern::whereKey($s->concern_id)->lockForUpdate()->firstOrFail();
                if ($concern->apprentice_id !== $a->id || $concern->status !== 'Closed') {
                    self::fail('Resolve the linked support concern in its own record before closing this follow-up.');
                }
            }
            $s->followup_history = [...($s->followup_history ?? []), ['action' => $resolve ? 'resolved' : 'reopened', 'by' => $actor->id, 'at' => now()->toIso8601String(), 'note' => $data['note']]];
            $s->followup_resolved_at = $resolve ? now() : null;
            $s->followup_resolved_by = $resolve ? $actor->id : null;
            $s->version++;
            $s->save();
            $x->version++;
            $x->save();
            self::event($actor, $f, $x, $resolve ? 'followup_resolved' : 'followup_reopened', $x->version);
        });
    }

    public static function counts(User $u): array
    {
        $q = self::queue($u);
        $rows = (clone $q)->select('state')->get();
        $open = ['Assigned', 'In progress'];

        return ['assigned' => $rows->where('state', 'Assigned')->count(), 'in_progress' => $rows->where('state', 'In progress')->count(), 'submitted' => $rows->where('state', 'Submitted')->count(), 'checked' => $rows->where('state', 'Checked')->count(), 'cancelled' => $rows->where('state', 'Cancelled')->count(), 'overdue' => (clone $q)->whereIn('state', $open)->whereDate('due_date', '<', today())->whereHas('form', fn ($f) => $f->where('kind', '!=', 'ongoing'))->count(), 'followup_required' => Submission::whereIn('assignment_id', (clone $q)->where('state', 'Checked')->select('id'))->where('state', 'Checked')->where('followup', 'Required')->whereNull('followup_resolved_at')->count()];
    }

    private static function event(User $u, Form $f, ?Assignment $x, string $event, int $v, ?string $reason = null): void
    {
        DB::table('portal_questionnaire_events')->insert(['assignment_id' => $x?->id, 'form_id' => $f->id, 'actor_id' => $u->id, 'event' => $event, 'record_version' => $v, 'private_reason' => $reason === null ? null : encrypt($reason), 'created_at' => now()]);
    }
}

<?php

namespace App\Support;

use App\Models\Apprentice;
use App\Models\LearningTask;
use App\Models\ProgressReviewDraft;
use App\Models\ReviewPreparationBrief;
use App\Models\SmartAction;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class ReviewBriefs
{
    public const FIELDS = ['learning' => 'Learning covered', 'progressDiscussion' => 'Progress discussion notes', 'previousNotes' => 'Previous actions notes', 'summary' => 'Preparation summary'];

    public static function allowed(User $u, Apprentice $a): bool
    {
        return SignedReviews::allowed($u, $a);
    }

    private static function fail(string $message): never
    {
        throw ValidationException::withMessages(['brief' => $message]);
    }

    public static function source(Apprentice $a, ProgressReviewDraft $review, string $from, string $to, bool $lock = false): array
    {
        $tasks = LearningTask::where('apprentice_id', $a->id)->orderBy('id')->when($lock, fn ($q) => $q->lockForUpdate())->get()->map(function ($t) use ($from, $to): array {
            $d = collect($t->submissions ?? [])->last()['decision'] ?? [];
            $date = $d['date'] ?? null;
            $valid = StaffTrainingRecords::validDate($date);

            return ['id' => $t->id, 'reference' => $t->reference, 'title' => $t->title, 'block' => $t->block, 'version' => $t->version, 'status' => $t->status, 'due' => $t->due_date?->toDateString(), 'decision_date' => $valid ? $date : null, 'feedback' => $valid && $date >= $from && $date <= $to ? Str::limit((string) ($d['feedback'] ?? ''), 800, ' [excerpt ends]') : null];
        })->all();
        $actions = SmartAction::with(['task' => fn ($q) => $q->where('apprentice_id', $a->id)])->where('apprentice_id', $a->id)->orderBy('id')->when($lock, fn ($q) => $q->lockForUpdate())->get()->map(fn ($x) => ['id' => $x->id, 'title' => $x->title, 'version' => $x->version, 'owner' => $x->owner, 'status' => $x->learning_task_id && ! $x->task ? 'Source needs checking' : $x->currentStatus(), 'due' => $x->learning_task_id && ! $x->task ? null : $x->deadline()?->toDateString(), 'completion' => $x->completion_date?->toDateString(), 'linked_task' => $x->learning_task_id])->all();

        return ['as_of' => today()->toDateString(), 'period_from' => $from, 'period_to' => $to, 'profile' => $a->only('id', 'version', 'reference', 'tutor_id', 'mentor_id', 'programme_id'), 'review' => ['id' => $review->id, 'version' => $review->version, 'state' => $review->state, 'fields_hash' => ReviewedImports::hash($review->fields ?? []), 'snapshot_hash' => ReviewedImports::hash($review->source_snapshot ?? [])], 'automation'=>ReviewAutomation::capture($a,$from,$to,$lock), 'tasks' => $tasks, 'attendance' => StaffTrainingRecords::attendance($a, $from, $to, $lock), 'actions' => $actions];
    }

    public static function suggest(array $s): array
    {
        $tasks = collect($s['tasks']);
        $approved = $tasks->where('status', 'Approved');
        $decisions = $tasks->filter(fn ($x) => $x['decision_date'] && $x['decision_date'] >= $s['period_from'] && $x['decision_date'] <= $s['period_to'])->sortBy(fn ($x) => $x['decision_date'].'-'.str_pad((string) $x['id'], 12, '0', STR_PAD_LEFT))->values();
        $report = StaffTrainingRecords::report($s['attendance']);
        $learning = ['Recorded task decisions during '.$s['period_from'].' to '.$s['period_to'].'. These are task decisions, not a complete record of all learning.'];
        foreach ($decisions->take(12) as $t) {
            $learning[] = $t['decision_date'].' — '.$t['reference'].' '.Str::limit($t['title'], 160).' — '.$t['status'];
        }
        if (! $decisions->count()) {
            $learning[] = 'No dated task decisions found in this period; check other learning records with the apprentice.';
        } elseif ($decisions->count() > 12) {
            $learning[] = ($decisions->count() - 12).' further decisions are listed in the captured sources.';
        }
        $progress = ['Current allocated task position captured '.$s['as_of'].': '.$approved->count().' approved of '.$tasks->count().' allocated; '.$tasks->where('status', 'Awaiting marking')->count().' awaiting marking. This is not a historical period-end reconstruction, KSB judgement or Gateway decision.'];
        $feedback = $decisions->filter(fn ($x) => filled($x['feedback']));
        foreach ($feedback->take(12) as $t) {
            $progress[] = 'Tutor feedback excerpt ('.$t['reference'].', '.$t['decision_date'].'): '.$t['feedback'];
        }if ($feedback->count() > 12) {
            $progress[] = ($feedback->count() - 12).' further feedback excerpts are in the captured sources.';
        }
        $unknown = $approved->whereNull('decision_date')->count();
        if ($unknown) {
            $progress[] = $unknown.' approved task(s) have no reliable decision date; they are not attributed to this period.';
        }
        $previous = ['Current agreed action records captured '.$s['as_of'].'; not reconstructed as they stood at period end. Check which belong to the previous review.'];
        foreach (collect($s['actions'])->take(20) as $x) {
            $previous[] = Str::limit($x['title'], 160).' — '.$x['owner'].' — '.$x['status'].' — Due '.($x['due'] ?? 'not recorded').' — Completed '.($x['completion'] ?? 'not recorded');
        }
        if (! $s['actions']) {
            $previous[] = 'No action records loaded. Confirm previous actions; do not assume this was a first review.';
        } elseif (count($s['actions']) > 20) {
            $previous[] = (count($s['actions']) - 20).' further actions are listed in the captured sources.';
        }
        $summary = ['PREPARATION NOTES — check/edit before use; no meeting or participant contribution is inferred.', 'Period '.$s['period_from'].' to '.$s['period_to'].'. Completed saved sessions: '.$report['attended'].' attended/technical course; '.$report['missed'].' absent/holiday/sickness/kept at work; '.$report['excluded'].' excluded (closed/BIL/EPA); '.$report['unmarked'].' unmarked or invalid; '.$report['cancelled'].' cancelled.'];
        $summary[] = $report['percent'] === null ? 'Attendance percentage unavailable from this loaded period.' : 'Loaded-session attendance: '.round($report['percent'], 1).'%. Not whole-programme attendance.';
        $summary[] = 'Current task approvals: '.$approved->count().'/'.$tasks->count().'. OTJ hours, qualifications, support needs, employer participation and progress judgement require their own checked sources. Discuss learning, feedback and previous actions, then record the actual three-way discussion.';

        if(isset($s['automation'])){
            $notes=ReviewAutomation::notes($s['automation']);
            $learning=[...$learning,...$notes['learning']];$progress=[...$progress,...$notes['progressDiscussion']];$summary=[...$summary,...$notes['summary']];
            foreach($tasks->groupBy('block') as $block=>$rows){$progress[]='Current block '.$block.': '.$rows->where('status','Approved')->count().'/'.$rows->count().' allocated tasks approved. Check actual progress against the approved plan; this is not KSB achievement.';}
        }
        return ['learning' => implode("\n", $learning), 'progressDiscussion' => implode("\n\n", $progress), 'previousNotes' => implode("\n", $previous), 'summary' => implode("\n\n", $summary)];
    }

    public static function prepare(User $actor, int $authVersion, ProgressReviewDraft $review, array $data): ReviewPreparationBrief
    {
        return DB::transaction(function () use ($actor, $authVersion, $review, $data) {
            $a = Apprentice::whereKey($review->apprentice_id)->lockForUpdate()->firstOrFail();
            $u = User::whereKey($actor->id)->lockForUpdate()->firstOrFail();
            abort_unless($u->auth_version === $authVersion && self::allowed($u, $a), 403);
            $r = ProgressReviewDraft::whereKey($review->id)->lockForUpdate()->firstOrFail();
            if ($r->state !== 'Draft' || $r->version !== (int) $data['version']) {
                self::fail('Use the current saved editable review draft. Save typed changes before generating notes.');
            }
            if (ReviewPreparationBrief::where('prepared_by', $u->id)->where('created_at', '>=', now()->subDay())->count() >= 50) {
                self::fail('Fifty preparation captures in the last day. Reuse the current notes or contact GTA.');
            }
            $source = self::source($a, $r, $data['period_from'], $data['period_to'], true);
            $brief = ReviewPreparationBrief::create(['id' => (string) Str::uuid(), 'apprentice_id' => $a->id, 'progress_review_draft_id' => $r->id, 'prepared_by' => $u->id, 'period_from' => $data['period_from'], 'period_to' => $data['period_to'], 'source' => $source, 'source_hash' => ReviewedImports::hash($source), 'suggested' => self::suggest($source)]);
            self::event($u, $a, $r, 'review_brief_prepared');

            return $brief;
        });
    }

    public static function intact(ReviewPreparationBrief $b): bool
    {
        try {
            return ReviewedImports::hash($b->source) === $b->source_hash && $b->source['profile']['id'] === $b->apprentice_id && $b->source['review']['id'] === $b->progress_review_draft_id && $b->source['period_from'] === $b->period_from->toDateString() && $b->source['period_to'] === $b->period_to->toDateString() && ReviewedImports::hash(self::suggest($b->source)) === ReviewedImports::hash($b->suggested);
        } catch (\Throwable) {
            return false;
        }
    }

    public static function apply(User $actor, int $authVersion, ReviewPreparationBrief $brief, array $data): void
    {
        DB::transaction(function () use ($actor, $authVersion, $brief, $data): void {
            $a = Apprentice::whereKey($brief->apprentice_id)->lockForUpdate()->firstOrFail();
            $u = User::whereKey($actor->id)->lockForUpdate()->firstOrFail();
            abort_unless($u->auth_version === $authVersion && self::allowed($u, $a), 403);
            $r = ProgressReviewDraft::whereKey($brief->progress_review_draft_id)->lockForUpdate()->firstOrFail();
            $b = ReviewPreparationBrief::whereKey($brief->id)->lockForUpdate()->firstOrFail();
            if ($r->apprentice_id !== $a->id || $r->state !== 'Draft' || $r->version !== (int) $data['version'] || $b->applied_at) {
                self::fail('Review or preparation changed, was frozen or has already been used. Reopen the current saved review.');
            }
            if (! self::intact($b)) {
                self::fail('The captured preparation source needs checking; generate new notes.');
            }
            $source = self::source($a, $r, $b->period_from->toDateString(), $b->period_to->toDateString(), true);
            if (collect($source['actions'])->contains('status', 'Source needs checking')) {
                self::fail('An action task link needs checking before notes can be applied.');
            }
            if (ReviewedImports::hash($source) !== $b->source_hash) {
                self::fail('Training records, assignments, review content or capture day changed. Generate new preparation notes.');
            }
            $fields = $r->fields ?? [];
            $selected = $data['selected'];
            foreach ($selected as $key) {
                if (! array_key_exists($key, self::FIELDS) || ! filled($data['text'][$key] ?? null)) {
                    self::fail('Select permitted sections with checked text.');
                }if (filled($fields[$key] ?? null) && ! ($data['replace_existing'] ?? false)) {
                    self::fail('Existing text would be replaced. Check the explicit replacement box or deselect that section.');
                }
            }
            $r->history = [...($r->history ?? []), ['event' => 'assistant_notes_applied', 'brief_id' => $b->id, 'fields' => $r->fields, 'source_snapshot' => $r->source_snapshot, 'saved_by' => $u->id, 'saved_at' => now()->toIso8601String()]];
            foreach ($selected as $key) {
                $fields[$key] = $data['text'][$key];
            }
            // Manual discussion/plan/preparation checks must be made again after any assistant insertion.
            foreach (['providerPrepared', 'trainingChecked', 'targetsAgreed'] as $key) {
                $fields[$key] = false;
            }
            $r->fields = $fields;
            $r->source_snapshot = ReviewSnapshot::capture($a, $fields) + ['assistant_preparation' => ['brief_id' => $b->id, 'source_hash' => $b->source_hash, 'period_from' => $b->period_from->toDateString(), 'period_to' => $b->period_to->toDateString(), 'checked_by' => $u->id, 'applied_at' => now()->toIso8601String()]];
            $r->version++;
            $r->save();
            $b->applied = collect($data['text'])->only($selected)->all();
            $b->applied_by = $u->id;
            $b->applied_at = now();
            $b->applied_review_version = $r->version;
            $b->save();
            self::event($u, $a, $r, 'review_brief_applied');
        });
    }

    private static function event(User $u, Apprentice $a, ProgressReviewDraft $r, string $event): void
    {
        DB::table('learning_events')->insert(['user_id' => $u->id, 'apprentice_id' => $a->id, 'kind' => 'review_draft', 'record_id' => $r->id, 'event' => $event, 'created_at' => now()]);
    }
}

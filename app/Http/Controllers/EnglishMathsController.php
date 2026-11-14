<?php

namespace App\Http\Controllers;

use App\Models\Apprentice;
use App\Models\Qualification;
use App\Models\TrainingTopic;
use App\Support\LearningAccess as Access;
use App\Support\PrivateEvidence;
use App\Support\EnglishMathsFollowup;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

class EnglishMathsController extends Controller
{
    public function caseload(Request $r): View
    {
        $u = $r->user();
        abort_unless($u->active && (in_array($u->role, ['admin', 'ceo', 'tutor', 'apprentice', 'functional_skills_tutor'], true) || \App\Support\StaffDuties::has($u,'maths_tutor') || \App\Support\StaffDuties::has($u,'english_tutor')), 403);
        $data = $r->validate(['search' => ['nullable', 'string', 'max:100'], 'subject' => ['nullable', Rule::in(['all', 'maths', 'english'])], 'view' => ['nullable', Rule::in(EnglishMathsFollowup::VIEWS)], 'page' => ['nullable', 'integer', 'min:1']]);
        $query = Apprentice::query();
        if (! Access::manager($u)) {
            $maths=\App\Support\StaffDuties::has($u,'maths_tutor');
            $english=\App\Support\StaffDuties::has($u,'english_tutor');
            $query->where(function($q) use($u,$maths,$english) {
                $q->whereRaw('1 = 0');
                if($u->role==='tutor') { $q->orWhere('tutor_id',$u->id); }
                if($u->role==='apprentice') { $q->orWhere('user_id',$u->id); }
                if($maths) { $q->orWhere('maths_tutor_id',$u->id); }
                if($english) { $q->orWhere('english_tutor_id',$u->id); }
            });
        }

        $search = trim($data['search'] ?? '');
        $people = $query->orderBy('last_name')->orderBy('first_name')->orderBy('id')->get();
        if ($search !== '') {
            $people = $people->filter(fn ($a) => Str::contains(Str::lower($a->first_name.' '.$a->last_name.' '.$a->reference), Str::lower($search)));
        }
        $subject = $data['subject'] ?? 'all';
        $view = $data['view'] ?? 'all';
        $summary = EnglishMathsFollowup::for($u, $people, $subject);
        $rows = $summary['rows']->filter(fn ($row) => $view === 'all' || $row['flags'][$view])->values();
        $page = min((int) ($data['page'] ?? 1), max(1, (int) ceil($rows->count() / 20)));
        $paginator = new LengthAwarePaginator($rows->slice(($page - 1) * 20, 20)->values(), $rows->count(), 20, $page, ['path' => $r->url(), 'query' => $r->except('page')]);
        return view('em.caseload', ['followupRows' => $paginator, 'followupCounts' => $summary['counts'], 'selectedSubject' => $subject, 'selectedView' => $view, 'search' => $search]);
    }

    private function subjects(Request $r, Apprentice $a): array
    {
        return EnglishMathsFollowup::subjects($r->user(), $a);
    }

    public function index(Request $r, Apprentice $apprentice): View
    {
        $subjects = $this->subjects($r, $apprentice);
        abort_unless($subjects, 403);
        $data = $r->validate(['show' => ['nullable', Rule::in(['working', 'achieved', 'overdue', 'today', 'checks'])], 'topics' => ['nullable', Rule::in(['awaiting', 'referred'])], 'subject' => ['nullable', Rule::in(['maths', 'english'])]]);
        if (isset($data['subject'])) {
            abort_unless(in_array($data['subject'], $subjects, true), 403);
            $subjects = [$data['subject']];
        }
        $topics = TrainingTopic::where('apprentice_id', $apprentice->id)->whereIn('subject', $subjects)->orderBy('subject')->orderBy('topic')->get();
        $quals = Qualification::where('apprentice_id', $apprentice->id)->whereIn('subject', $subjects)->orderBy('subject')->get();
        $filter = $r->query('show');
        $quals = $quals->filter(fn ($q) => match ($filter) {
            'working' => $q->status === 'Working towards','achieved' => $q->achieved(),'overdue' => $q->position() === 'Overdue','today' => $q->position() === 'Due today','checks' => $q->position() === 'Date check',default => true
        });

        return view('em.index', ['apprentice' => $apprentice, 'subjects' => $subjects, 'topics' => $topics, 'topicRows' => $topics->filter(fn ($t) => match ($data['topics'] ?? '') { 'awaiting' => $t->evidence_stage === 'Awaiting review', 'referred' => $t->evidence_stage === 'Referred', default => true }), 'qualifications' => $quals, 'catalogue' => $this->catalogue(), 'canManage' => Access::manager($r->user())]);
    }

    public function assign(Request $r, Apprentice $apprentice): RedirectResponse
    {
        abort_unless(Access::subject($r->user(), $apprentice, 'maths') || Access::subject($r->user(), $apprentice, 'english'), 403);
        $catalogue = $this->catalogue();
        $data = $r->validate(['catalogue_id' => ['required', 'integer', Rule::in(array_keys($catalogue))], 'planned_hours' => ['required', 'numeric', 'min:0', 'max:9999']]);
        $source = $catalogue[(int) $data['catalogue_id']];
        $subject = str_starts_with($source['subject'], 'Maths') ? 'maths' : 'english';
        abort_unless(Access::subject($r->user(), $apprentice, $subject), 403);
        DB::transaction(function () use ($r, $apprentice, $source, $subject, $data): void {
            $fresh=Apprentice::whereKey($apprentice->id)->lockForUpdate()->firstOrFail();
            abort_unless(Access::subject($r->user(),$fresh,$subject),403);
            $level = explode(': ', $source['subject'])[1];
            if (TrainingTopic::where(['apprentice_id' => $apprentice->id, 'subject' => $subject, 'level' => $level, 'topic' => $source['topic']])->exists()) {
                throw ValidationException::withMessages(['catalogue_id' => 'This topic is already assigned.']);
            }$t = new TrainingTopic(['apprentice_id' => $apprentice->id, 'subject' => $subject, 'level' => $level, 'topic' => $source['topic'], 'area' => $source['area'], 'planned_hours' => $data['planned_hours']]);
            $t->status = (float) $data['planned_hours'] === 0.0 ? 'Secure' : 'Not Started';
            $t->save();
            $this->audit($r, $apprentice->id, 'topic', $t->id, 'assigned');
        });

        return back()->with('status', 'Topic and individually planned training hours saved. Zero hours means secure, not completed training.');
    }

    public function topic(Request $r, TrainingTopic $topic): View
    {
        abort_unless(in_array($topic->subject, $this->subjects($r, $topic->apprentice), true), 403);

        return view('em.topic', ['topic' => $topic, 'apprentice' => $topic->apprentice, 'canReview' => Access::subject($r->user(), $topic->apprentice, $topic->subject), 'learner' => Access::learner($r->user(), $topic->apprentice)]);
    }

    public function evidence(Request $r, TrainingTopic $topic): RedirectResponse
    {
        abort_unless(Access::learner($r->user(), $topic->apprentice), 403);
        $data = $r->validate(['version' => ['required', 'integer'], 'intent' => ['required', Rule::in(['draft', 'submit'])], 'draft' => ['nullable', 'string', 'max:50000'], 'own_work' => ['sometimes', 'accepted']]);
        DB::transaction(function () use ($r, $topic, $data): void {
            $t = TrainingTopic::whereKey($topic->id)->lockForUpdate()->firstOrFail();
            $this->version($r, $t);
            $fresh=Apprentice::whereKey($t->apprentice_id)->lockForUpdate()->firstOrFail();
            abort_unless(Access::learner($r->user(),$fresh),403);
            if (! $t->editable()) {
                throw ValidationException::withMessages(['intent' => 'This topic evidence is locked.']);
            }if ($data['intent'] === 'submit') {
                $r->validate(['own_work' => ['accepted']]);
                if (! filled($data['draft'] ?? null) && ! $t->files) {
                    throw ValidationException::withMessages(['draft' => 'Add a comment, evidence reference or file before submitting.']);
                }$history = $t->submissions ?? [];
                $history[] = ['version' => count($history) + 1, 'comment' => $data['draft'] ?? null, 'files' => $t->files ?? [], 'submitted_at' => now()->toIso8601String(), 'own_work' => true, 'decision' => null];
                $t->submissions = $history;
                $t->evidence_stage = 'Awaiting review';
            }$t->draft = $data['draft'] ?? null;
            $t->version++;
            $t->save();
            $this->audit($r, $t->apprentice_id, 'topic', $t->id, $data['intent'] === 'submit' ? 'submitted' : 'draft_saved');
        });

        return back()->with('status', 'Topic evidence saved. Training completion and qualification results remain separate.');
    }

    public function updateTopic(Request $r, TrainingTopic $topic): RedirectResponse
    {
        abort_unless(Access::subject($r->user(), $topic->apprentice, $topic->subject), 403);
        $data = $r->validate(['version' => ['required', 'integer'], 'status' => ['required', Rule::in(['Not Started', 'In Progress', 'Completed', 'Secure'])], 'completion_date' => ['nullable', 'required_if:status,Completed', 'date_format:Y-m-d', 'before_or_equal:today'], 'feedback' => ['required', 'string', 'max:20000'], 'decision' => ['nullable', Rule::in(['Accepted', 'Referred'])]]);
        DB::transaction(function () use ($r, $topic, $data): void {
            $t = TrainingTopic::whereKey($topic->id)->lockForUpdate()->firstOrFail();
            $this->version($r, $t);
            $fresh=Apprentice::whereKey($t->apprentice_id)->lockForUpdate()->firstOrFail();
            abort_unless(Access::subject($r->user(),$fresh,$t->subject),403);
            if (((float) $t->planned_hours === 0.0) !== ($data['status'] === 'Secure')) {
                throw ValidationException::withMessages(['status' => 'Zero-hour areas must remain Secure; only areas with planned training can be marked Completed.']);
            }
            $history = $t->submissions ?? [];
            $decision = $data['decision'] ?? null;
            if ($decision) {
                if ($t->evidence_stage !== 'Awaiting review' || ! count($history)) {
                    throw ValidationException::withMessages(['decision' => 'There is no current submission awaiting review.']);
                }$history[count($history) - 1]['decision'] = ['outcome' => $decision, 'feedback' => $data['feedback'], 'tutor_id' => $r->user()->id, 'recorded_at' => now()->toIso8601String()];
                $t->evidence_stage = $decision;
            }
            if ($data['status'] === 'Completed' && in_array($t->evidence_stage, ['Referred', 'Awaiting review'], true)) {
                throw ValidationException::withMessages(['status' => 'Resolve the pending or referred evidence before recording completion.']);
            }
            $updates = $t->history ?? [];
            $updates[] = ['before' => $t->status, 'after' => $data['status'], 'feedback' => $data['feedback'], 'tutor_id' => $r->user()->id, 'completion_date' => $data['completion_date'] ?? null, 'at' => now()->toIso8601String()];
            $t->history = $updates;
            $t->submissions = $history;
            $t->status = $data['status'];
            $t->completion_date = $data['status'] === 'Completed' ? $data['completion_date'] : null;
            $t->version++;
            $t->save();
            $this->audit($r, $t->apprentice_id, 'topic', $t->id, 'tutor_updated');
        });

        return back()->with('status', 'Topic update saved. Evidence acceptance alone does not complete training or a qualification.');
    }

    public function upload(Request $r, TrainingTopic $topic): RedirectResponse
    {
        abort_unless(Access::learner($r->user(), $topic->apprentice) || Access::subject($r->user(), $topic->apprentice, $topic->subject), 403);
        $r->validate(['version' => ['required', 'integer'], 'file' => ['required', 'file', 'mimes:pdf,jpg,jpeg,png,docx', 'extensions:pdf,jpg,jpeg,png,docx', 'max:15360']]);
        $entry = null;
        try {
            DB::transaction(function () use ($r, $topic, &$entry): void {
                $t = TrainingTopic::whereKey($topic->id)->lockForUpdate()->firstOrFail();
                $this->version($r, $t);
                $fresh=Apprentice::whereKey($t->apprentice_id)->lockForUpdate()->firstOrFail();
                abort_unless(Access::learner($r->user(),$fresh)||Access::subject($r->user(),$fresh,$t->subject),403);
                if (! $t->editable()) {
                    throw ValidationException::withMessages(['file' => 'Save is locked while evidence awaits review or training is complete.']);
                }$entry = PrivateEvidence::store($r->file('file'), 'topic-evidence', $r->user()->id);
                $files = $t->files ?? [];
                $files[] = [...$entry, 'by' => $r->user()->id];
                $t->files = $files;
                $t->version++;
                $t->save();
                $this->audit($r, $t->apprentice_id, 'topic', $t->id, 'evidence_added');
            });
        } catch (\Throwable $e) {
            if ($entry) {
                PrivateEvidence::delete($entry);
            }throw $e;
        }

        return back()->with('status', 'Topic evidence uploaded privately.');
    }

    public function download(Request $r, TrainingTopic $topic, string $file): StreamedResponse
    {
        abort_unless(in_array($topic->subject, $this->subjects($r, $topic->apprentice), true), 403);
        $entry = collect($topic->files ?? [])->firstWhere('id', $file);
        abort_unless($entry && PrivateEvidence::exists($entry), 404);

        return PrivateEvidence::download($entry);
    }

    public function qualificationForm(Request $r, Apprentice $apprentice, ?Qualification $qualification = null): View
    {
        abort_unless(Access::subject($r->user(), $apprentice, $qualification?->subject ?? 'maths') || (! $qualification && Access::subject($r->user(), $apprentice, 'english')), 403);
        if ($qualification) {
            abort_unless($qualification->apprentice_id === $apprentice->id, 404);
        }

        return view('em.qualification', ['qualification' => $qualification ?? new Qualification, 'apprentice' => $apprentice, 'subjects' => array_values(array_filter(['maths', 'english'], fn ($s) => Access::subject($r->user(), $apprentice, $s)))]);
    }

    public function uploadQualification(Request $r, Qualification $qualification): RedirectResponse
    {
        abort_unless(Access::subject($r->user(), $qualification->apprentice, $qualification->subject), 403);
        $data = $r->validate(['version' => ['required', 'integer'], 'file' => PrivateEvidence::rules(), 'purpose' => ['required', 'string', 'max:2000']]);
        $entry = null;
        try {
            DB::transaction(function () use ($r, $qualification, $data, &$entry): void {
                $q = Qualification::whereKey($qualification->id)->lockForUpdate()->firstOrFail();
                $fresh=Apprentice::whereKey($q->apprentice_id)->lockForUpdate()->firstOrFail();
                abort_unless(Access::subject($r->user(),$fresh,$q->subject),403);
                if ($q->version !== $r->integer('version')) {
                    throw ValidationException::withMessages(['version' => 'This qualification changed. Reopen before uploading evidence.']);
                }
                $entry = PrivateEvidence::store($r->file('file'), 'qualification-evidence', $r->user()->id);
                $files = $q->files ?? [];
                $files[] = [...$entry, 'purpose' => $data['purpose']];
                $q->files = $files;
                $q->version++;
                $q->save();
                $this->audit($r, $q->apprentice_id, 'qualification', $q->id, 'evidence_uploaded');
            });
        } catch (\Throwable $error) {
            if ($entry) {
                PrivateEvidence::delete($entry);
            } throw $error;
        }

        return back()->with('status', 'Qualification evidence saved privately. The recorded result and achievement status have not changed.');
    }

    public function downloadQualification(Request $r, Qualification $qualification, string $file): StreamedResponse
    {
        abort_unless(in_array($qualification->subject, $this->subjects($r, $qualification->apprentice), true), 403);
        $entry = collect($qualification->files ?? [])->firstWhere('id', $file);
        abort_unless($entry && PrivateEvidence::exists($entry), 404);

        return PrivateEvidence::download($entry);
    }

    public function saveQualification(Request $r, Apprentice $apprentice, ?Qualification $qualification = null): RedirectResponse
    {
        abort_unless(Access::subject($r->user(), $apprentice, 'maths') || Access::subject($r->user(), $apprentice, 'english'), 403);
        if ($qualification) {
            abort_unless($qualification->apprentice_id === $apprentice->id, 404);
            abort_unless(Access::subject($r->user(), $apprentice, $qualification->subject), 403);
        }
        $data = $r->validate(['subject' => ['required', Rule::in(['maths', 'english'])], 'type' => ['required', Rule::in(['Functional Skills', 'GCSE', 'Other'])], 'title' => ['required', 'string', 'max:250'], 'level' => ['required', Rule::in(['Entry Level 1', 'Entry Level 2', 'Entry Level 3', 'Level 1', 'Level 2', 'Other / confirm level'])], 'status' => ['required', Rule::in(Qualification::STATUSES)], 'start_date' => ['nullable', 'required_if:status,Working towards', 'date_format:Y-m-d'], 'result' => ['nullable', 'string', 'max:100'], 'achievement_date' => ['nullable', 'required_if:status,Achieved,Already held', 'date_format:Y-m-d', 'before_or_equal:today'], 'notes' => ['nullable', 'string', 'max:20000'], 'change_reason' => ['nullable', 'string', 'max:2000'], 'version' => $qualification ? ['required', 'integer'] : ['prohibited']]);
        abort_unless(Access::subject($r->user(), $apprentice, $data['subject']), 403);
        if ($data['status'] === 'Achieved' && filled($data['start_date'] ?? null) && ($data['achievement_date'] ?? '') < $data['start_date']) {
            throw ValidationException::withMessages(['achievement_date' => 'Achievement cannot be before this qualification start date.']);
        }
        $achieved = in_array($data['status'], ['Achieved', 'Already held'], true);
        if ($data['type'] === 'Functional Skills') {
            if (filled($data['result'] ?? null) && ($data['result'] !== 'Achieved' || ! $achieved)) {
                throw ValidationException::withMessages(['result' => 'Functional Skills uses Achieved, without grade bands.']);
            }$data['result'] = $achieved ? 'Achieved' : null;
        }
        if ($achieved && ! filled($data['result'] ?? null)) {
            throw ValidationException::withMessages(['result' => 'Record the actual qualification result.']);
        }
        if (! $achieved && filled($data['achievement_date'] ?? null)) {
            throw ValidationException::withMessages(['achievement_date' => 'Achievement dates apply only to an achieved or already-held qualification.']);
        }
        DB::transaction(function () use ($r, $apprentice, $qualification, $data): void {
            $q = $qualification ? Qualification::whereKey($qualification->id)->lockForUpdate()->firstOrFail() : new Qualification(['apprentice_id' => $apprentice->id]);
            $fresh=Apprentice::whereKey($apprentice->id)->lockForUpdate()->firstOrFail();
            abort_unless(Access::subject($r->user(),$fresh,$data['subject']) && (!$q->exists || Access::subject($r->user(),$fresh,$q->subject)),403);
            if ($q->exists) {
                $this->version($r, $q);
                if ($q->start_date?->toDateString() !== ($data['start_date'] ?? null) && ! filled($data['change_reason'] ?? null)) {
                    throw ValidationException::withMessages(['change_reason' => 'Explain the change to the custom start date; it changes the twelve-month target.']);
                }
            }
            $history = $q->history ?? [];
            $history[] = ['before' => $q->only('subject', 'type', 'title', 'level', 'status', 'result', 'start_date', 'achievement_date'), 'reason' => $data['change_reason'] ?? null, 'by' => $r->user()->id, 'at' => now()->toIso8601String()];
            $q->fill(collect($data)->except('version', 'change_reason')->all());
            $q->history = $history;
            if ($q->exists) {
                $q->version++;
            }$q->save();
            $this->audit($r, $apprentice->id, 'qualification', $q->id, 'saved');
        });

        return to_route('em.index', $apprentice)->with('status', 'Qualification result/status saved separately from training topics.');
    }

    private function catalogue(): array
    {
        return json_decode(file_get_contents(resource_path('data/topic-catalogue.json')), true, 512, JSON_THROW_ON_ERROR);
    }

    private function version(Request $r, Model $row): void
    {
        if ($row->version !== $r->integer('version')) {
            throw ValidationException::withMessages(['version' => 'This record changed. Reopen before saving.']);
        }
    }

    private function audit(Request $r, int $a, string $kind, int $id, string $event): void
    {
        DB::table('learning_events')->insert(['user_id' => $r->user()->id, 'apprentice_id' => $a, 'kind' => $kind, 'record_id' => $id, 'event' => $event, 'created_at' => now()]);
    }
}

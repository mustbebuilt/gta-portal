<?php

namespace App\Http\Controllers;

use App\Models\Apprentice;
use App\Models\LearningBlockControl;
use App\Models\LearningTask;
use App\Models\TaskEvidence;
use App\Support\BlockRelease;
use App\Support\CurriculumSources;
use App\Support\LearningAccess as Access;
use App\Support\MarkingQueueViews;
use App\Support\MarkingReminders;
use App\Support\PortalPdf;
use App\Support\PrivateEvidence;
use App\Support\TaskCatalogue;
use App\Support\TaskDeadlineHistory;
use App\Support\TaskPresentation;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;

class LearningTaskController extends Controller
{
    public function index(Request $r, Apprentice $apprentice): View
    {
        abort_unless(Access::training($r->user(), $apprentice), 403);
        $q = LearningTask::where('apprentice_id', $apprentice->id);
        if (in_array($r->query('status'), ['Draft', 'Awaiting marking', 'Referred', 'Approved', 'Source required'], true)) {
            $q->where('status', $r->query('status'));
        }

        return view('learning.index', ['blockControl' => LearningBlockControl::where('apprentice_id', $apprentice->id)->first(), 'apprentice' => $apprentice, 'tasks' => $q->orderBy('block')->orderBy('reference')->get(), 'manage' => Access::manager($r->user()), 'counts' => LearningTask::where('apprentice_id', $apprentice->id)->selectRaw('status, COUNT(*) AS total')->groupBy('status')->pluck('total', 'status')]);
    }

    public function allocate(Request $r, Apprentice $apprentice): RedirectResponse
    {
        abort_unless(Access::manager($r->user()), 403);
        $r->validate(['confirmed' => ['accepted']]);
        DB::transaction(function () use ($r, $apprentice): void {
            $fresh = Apprentice::whereKey($apprentice->id)->lockForUpdate()->firstOrFail();
            if ($fresh->programme_id) {
                $fresh->programmeRecord()->lockForUpdate()->first();
            }
            $key = TaskCatalogue::allocationKey($fresh);
            if ($key === null) {
                throw ValidationException::withMessages(['confirmed' => 'Link an active supported programme with the correct standard and version before assigning tasks. No tasks were assigned.']);
            }
            if (LearningTask::where('apprentice_id', $apprentice->id)->exists()) {
                throw ValidationException::withMessages(['confirmed' => 'Tasks are already assigned. No duplicate allocation was made.']);
            }
            if (! CurriculumSources::release($key)['technical_ok']) {
                throw ValidationException::withMessages(['confirmed' => 'The task catalogue or its PDF sources need checking. No tasks were assigned.']);
            }
            foreach (TaskCatalogue::tasks($key) as $t) {
                $task = new LearningTask(['apprentice_id' => $apprentice->id, 'reference' => $t['id'], 'block' => $t['block'], 'title' => $t['title'], 'theme' => $t['theme'], 'template' => [...TaskCatalogue::template($t['id'], $key), 'catalogueId' => $key]]);
                $task->status = $task->template['kind'] === 'source-pending' ? 'Source required' : 'Draft';
                $task->save();
            }
            LearningBlockControl::firstOrCreate(['apprentice_id' => $apprentice->id], ['enabled' => true, 'exceptions' => [], 'history' => [['event' => 'new_allocation', 'by' => $r->user()->id, 'at' => now()->toIso8601String()]]]);
            $this->audit($r, $apprentice->id, 0, 'allocated');
        });

        return back()->with('status', 'Assigned programme task slots. Dates and individual plan approval remain separate.');
    }

    public function loadReflectionSources(Request $r, Apprentice $apprentice): RedirectResponse
    {
        abort_unless(Access::manager($r->user()), 403);
        $r->validate(['confirmed' => ['accepted']]);
        $count = DB::transaction(function () use ($r, $apprentice): int {
            $fresh = Apprentice::whereKey($apprentice->id)->lockForUpdate()->firstOrFail();
            if ($fresh->programme_id) {
                $fresh->programmeRecord()->lockForUpdate()->first();
            }
            if (TaskCatalogue::keyFor($fresh) !== 'panel') {
                throw ValidationException::withMessages(['confirmed' => 'The linked programme/version must match the Panel source catalogue.']);
            }
            $hashes = json_decode(file_get_contents(resource_path('data/panel-placeholder-hashes.json')), true, 512, JSON_THROW_ON_ERROR);
            $tasks = LearningTask::where('apprentice_id', $fresh->id)->orderBy('id')->lockForUpdate()->get();
            $changes = [];
            foreach ($tasks as $t) {
                if ($t->status !== 'Source required' || ($t->template['kind'] ?? null) !== 'source-pending'
                    || TaskCatalogue::savedKey($t) !== 'panel' || ! isset($hashes[$t->reference])
                    || ($t->template['sha256'] ?? null) !== $hashes[$t->reference]
                    || (int) ($t->template['sourceBlock'] ?? 0) !== $t->block || (int) ($t->template['sourceTask'] ?? 0) !== 5
                    || $t->version !== 1 || ! empty($t->answers) || ! empty($t->submissions) || $t->submitted_at
                    || $t->evidence()->exists() || DB::table('learning_events')->where('kind', 'task')->where('record_id', $t->id)->exists()) {
                    continue;
                }
                $template = TaskCatalogue::template($t->reference, 'panel');
                if (($template['kind'] ?? null) !== 'impact') {
                    continue;
                }
                $changes[] = ['task_id' => $t->id, 'reference' => $t->reference, 'old_hash' => $t->template['sha256'], 'new_hash' => $template['sha256'], 'source_pdf_hash' => $template['sourceTemplateHash']];
                $t->template = $template;
                $t->title = 'Task 5: '.$template['title'];
                $t->status = 'Draft';
                $t->version++;
                $t->save();
                $this->audit($r, $fresh->id, $t->id, 'reflection_source_loaded');
            }
            if ($changes) {
                $control = LearningBlockControl::where('apprentice_id', $fresh->id)->lockForUpdate()->firstOrFail();
                $control->history = [...($control->history ?? []), ['event' => 'reflection_sources_loaded', 'by' => $r->user()->id, 'at' => now()->toIso8601String(), 'changes' => $changes]];
                $control->version++;
                $control->save();
            }

            return count($changes);
        });

        return back()->with('status', $count.' untouched reflection placeholders loaded. Other saved records were preserved.');
    }

    public function show(Request $r, LearningTask $task): View
    {
        abort_unless(Access::training($r->user(), $task->apprentice), 403);

        $release = BlockRelease::state($task);
        $queue = MarkingReminders::allowed($r->user()) && $r->boolean('from_marking')
            ? MarkingQueueViews::parameters($r->query()) : null;
        $presentation = TaskPresentation::forTask($task);
        $attempt = collect($task->submissions ?? [])->last();
        $awaiting = $task->status === 'Awaiting marking';
        $evidence = $task->evidence;
        if ($awaiting && isset($attempt['evidence'])) {
            $snapshot = collect($attempt['evidence'])->keyBy('id');
            $evidence = $evidence->filter(fn ($file) => $file->sha256 === ($snapshot->get($file->id)['sha256'] ?? null));
        }

        return view('learning.task', ['release' => $release, 'queue' => $queue, 'presentation' => $presentation,
            'currentAnswers' => $awaiting ? ($attempt['answers'] ?? $task->answers ?? []) : ($task->answers ?? []),
            'currentEvidence' => $evidence, 'planning' => collect(TaskCatalogue::savedKey($task) === 'autocare' ? json_decode(file_get_contents(resource_path('data/training-plan-reference.json')), true, 512, JSON_THROW_ON_ERROR)['curriculumAmendment']['block9Planning']['activities'] : [])->where('taskId', $task->reference), 'task' => $task, 'apprentice' => $task->apprentice, 'tutor' => ($release['open'] || $task->status === 'Awaiting marking') && (Access::tutor($r->user(), $task->apprentice) || Access::manager($r->user())), 'learner' => $release['open'] && Access::learner($r->user(), $task->apprentice)]);
    }

    public function save(Request $r, LearningTask $task): RedirectResponse
    {
        abort_unless(Access::learner($r->user(), $task->apprentice), 403);
        $r->validate(['checklist75_present' => ['prohibited'], 'checklist75' => ['prohibited']]);
        $r->validate(['version' => ['required', 'integer'], 'intent' => ['required', Rule::in(['draft', 'submit', 'upload'])], 'own_work' => ['sometimes', 'accepted']]);
        $files = $this->submissionFiles($r);
        $entries = [];
        try {
            DB::transaction(function () use ($r, $task, $files, &$entries): void {
                $t = LearningTask::whereKey($task->id)->lockForUpdate()->firstOrFail();
                abort_unless(Access::learner($r->user(), $t->apprentice), 403);
                BlockRelease::check($t);
                if (! $t->editable() || $t->template['kind'] === 'knowledge') {
                    throw ValidationException::withMessages(['intent' => 'This task cannot be edited or submitted in its current state.']);
                }
                $answers = $this->answers($r, $t, ['apprentice']);
                $this->recordActivities($r, $t, $answers);
                $missing = $this->newEvidenceFiles($r, $t, $files);
                // A retry of the immediately preceding draft/upload must not create a second version.
                if ($r->input('intent') !== 'submit' && $files && ! $missing
                    && $t->version === $r->integer('version') + 1 && $t->answers === $answers) {
                    return;
                }
                $this->version($r, $t);
                foreach ($missing as $upload) {
                    $entry = PrivateEvidence::store($upload['file'], 'task-evidence', $r->user()->id);
                    $entries[] = $entry;
                    TaskEvidence::create(['learning_task_id' => $t->id, 'uploaded_by' => $r->user()->id,
                        ...array_intersect_key($entry, array_flip(['disk', 'path', 'name', 'mime', 'sha256', 'bytes'])),
                        'kind' => $upload['kind'], 'description' => $upload['description']]);
                    $this->audit($r, $t->apprentice_id, $t->id, 'evidence_added');
                }
                if ($r->input('intent') === 'submit') {
                    $r->validate(['own_work' => ['accepted']]);
                    $names = array_filter($t->template['fields'], fn ($f) => $f['role'] === 'apprentice' && in_array($f['key'], ['apprentice_name', 'sign_apprentice_name', 'apprentice_signoff'], true));
                    if (! collect($names)->contains(fn ($f) => filled($answers[$f['name']] ?? null))) {
                        throw ValidationException::withMessages(['answers' => 'Enter the apprentice name before submitting.']);
                    }
                    if ($t->template['kind'] === 'impact') {
                        foreach ($t->template['fields'] as $f) {
                            if ($f['type'] === 'score' && ! filled($answers[$f['name']] ?? null)) {
                                if (! isset($t->template['unassessedReasonField'])) {
                                    throw ValidationException::withMessages(['answers' => 'Complete all START and END scores, or save a draft.']);
                                }
                                if (! filled($answers[$t->template['unassessedReasonField']] ?? null)) {
                                    throw ValidationException::withMessages(['answers' => 'Explain each unassessed START or END score in the practice/support field, or save a draft.']);
                                }
                            }
                        }
                    }
                    foreach ($t->template['adasFieldNames'] ?? [] as $name) {
                        if (! filled($answers[$name] ?? null)) {
                            throw ValidationException::withMessages(['answers.'.$name => 'Answer both released ADAS questions before submitting, or save a draft.']);
                        }
                    }
                    if (TaskCatalogue::savedKey($t) === 'autocare' && $t->reference === 'b1-t3' && ! $t->evidence()->where('kind', 'inspection-checklist')->exists()) {
                        throw ValidationException::withMessages(['answers' => 'Attach the completed inspection checklist before submitting.']);
                    }
                    $history = $t->submissions ?? [];
                    $history[] = ['version' => count($history) + 1, 'submitted_at' => now()->toIso8601String(), 'answers' => $answers, 'template_hash' => $t->template['sha256'], 'evidence' => $t->evidence()->get(['id', 'name', 'sha256', 'kind'])->toArray(), 'own_work' => true, 'decision' => null];
                    $t->submissions = $history;
                    $t->status = 'Awaiting marking';
                    $t->submitted_at = now();
                }
                $t->answers = $answers;
                $t->version++;
                $t->save();
                $this->audit($r, $t->apprentice_id, $t->id, $r->input('intent') === 'submit' ? 'submitted' : 'draft_saved');
            });
        } catch (\Throwable $error) {
            foreach ($entries as $entry) {
                PrivateEvidence::delete($entry);
            }
            throw $error;
        }

        return back()->with('status', $r->input('intent') === 'submit' ? 'Submitted to the tutor. This version is locked.' : ($files ? 'Answers and evidence saved together.' : 'Draft saved.'));
    }

    private function submissionFiles(Request $request): array
    {
        $uploads = $request->file('files');
        $batchSupplied = ($uploads !== null && $uploads !== []) || filled($request->input('files'));
        $singleSupplied = $request->file('file') !== null || filled($request->input('file'));
        $cameraSupplied = $request->file('camera_photo') !== null || filled($request->input('camera_photo'));
        $evidence = [];

        if ($batchSupplied || $singleSupplied || (! $cameraSupplied && $request->input('intent') === 'upload')) {
            $kind = $request->input('kind');
            $extensions = $kind === 'workplace-photo' ? 'jpg,jpeg,png' : 'pdf,jpg,jpeg,png,docx';
            $fileRules = ['required', 'file', 'mimes:'.$extensions, 'extensions:'.$extensions, 'max:15360'];
            $limit = $cameraSupplied ? 4 : 5;
            $fileInput = $batchSupplied
                ? ['files' => ['required', 'array', 'list', 'min:1', 'max:'.$limit, 'prohibits:file'], 'files.*' => $fileRules]
                : ['file' => $fileRules];
            $request->validate($fileInput + [
                'kind' => ['required', Rule::in(['supporting-evidence', 'inspection-checklist', 'workplace-photo'])],
                'description' => ['nullable', 'required_if:kind,workplace-photo', 'string', 'max:5000'],
            ]);
            foreach ($request->file('files') ?? [$request->file('file')] as $file) {
                $evidence[] = ['file' => $file, 'kind' => $kind, 'description' => $request->input('description')];
            }
        }

        if ($cameraSupplied) {
            $request->validate([
                'camera_photo' => ['required', 'file', 'mimes:jpg,jpeg,png', 'extensions:jpg,jpeg,png', 'max:15360'],
                'camera_description' => ['required', 'string', 'max:5000'],
            ]);
            $evidence[] = ['file' => $request->file('camera_photo'), 'kind' => 'workplace-photo',
                'description' => $request->input('camera_description')];
        }

        return $evidence;
    }

    private function newEvidenceFiles(Request $request, LearningTask $task, array $files): array
    {
        if (! $files) {
            return [];
        }

        $key = fn ($kind, $description, $sha) => hash('sha256', serialize([$kind, $description, $sha]));
        $known = $task->evidence()->where('uploaded_by', $request->user()->id)
            ->get(['kind', 'description', 'sha256'])->mapWithKeys(fn ($item) => [$key($item->kind, $item->description, $item->sha256) => true]);

        $pending = [];
        foreach ($files as $item) {
            $sha = hash_file('sha256', $item['file']->getRealPath());
            $id = $key($item['kind'], $item['description'], $sha);
            if (! $known->has($id)) {
                $pending[$id] ??= $item;
            }
        }

        return $pending;
    }

    private function recordActivities(Request $request, LearningTask $task, array &$answers): void
    {
        if (! $request->has('activities_present')) {
            unset($answers['_activities']);
            if (array_key_exists('_activities', $task->answers ?? [])) {
                $answers['_activities'] = $task->answers['_activities'];
            }

            return;
        }

        $posted = $request->validate([
            'activities_present' => ['accepted'], 'activities' => ['sometimes', 'array', 'list', 'max:100'],
        ]);
        $presentation = TaskPresentation::forTask($task);
        $steps = TaskPresentation::checklist($presentation);
        if (! $steps) {
            throw ValidationException::withMessages(['activities' => 'Activity checks are unavailable for this task version. Reopen the task.']);
        }
        try {
            $checked = TaskPresentation::checkedSteps($presentation, $posted['activities'] ?? []);
        } catch (\InvalidArgumentException $error) {
            throw ValidationException::withMessages(['activities' => $error->getMessage()]);
        }

        $answers['_activities'] = array_values(array_filter($steps, fn ($step) => in_array($step['id'], $checked, true)));
    }

    public function decide(Request $r, LearningTask $task): RedirectResponse
    {
        abort_unless(Access::tutor($r->user(), $task->apprentice) || Access::manager($r->user()), 403);
        $r->validate(['version' => ['required', 'integer'], 'decision' => ['required', Rule::in(['Approved', 'Referred'])], 'decision_date' => ['required', 'date_format:Y-m-d', 'before_or_equal:today'], 'feedback' => ['nullable', 'required_if:decision,Referred', 'string'], 'confirmed' => ['accepted'], 'after_save' => ['nullable', Rule::in(['stay', 'next'])]]);
        DB::transaction(function () use ($r, $task): void {
            $apprentice = Apprentice::whereKey($task->apprentice_id)->lockForUpdate()->firstOrFail();
            $t = LearningTask::whereKey($task->id)->lockForUpdate()->firstOrFail();
            abort_unless($t->apprentice_id === $apprentice->id
                && (Access::tutor($r->user(), $apprentice) || Access::manager($r->user())), 403);
            $t->setRelation('apprentice', $apprentice);
            $this->version($r, $t);
            if ($t->status !== 'Awaiting marking') {
                BlockRelease::check($t);
            }
            $knowledge = $t->template['kind'] === 'knowledge';
            if ((! $knowledge && $t->status !== 'Awaiting marking') || ($knowledge && ! $t->editable())) {
                throw ValidationException::withMessages(['decision' => 'Only the current awaiting submission can receive a decision.']);
            }
            if (! $knowledge && $t->submitted_at && $r->input('decision_date') < $t->submitted_at->toDateString()) {
                throw ValidationException::withMessages(['decision_date' => 'A tutor decision cannot precede the submitted work.']);
            }
            $evidence = $this->answers($r, $t, ['trainer', 'observer']);
            if ($knowledge) {
                $r->validate(['test_title' => ['required', 'string'], 'marks_available' => ['required', 'numeric', 'gt:0'], 'marks_achieved' => ['required', 'numeric', 'min:0', 'lte:marks_available'], 'test_evidence_reference' => ['nullable', 'string', 'max:2000']]);
                $evidence = [...$evidence, ...$r->only('test_title', 'marks_available', 'marks_achieved', 'test_evidence_reference')];
            }
            if ($t->template['kind'] === 'jobcard' && $r->input('decision') === 'Approved') {
                $r->validate(['answers.mentor_confirmation' => ['accepted'], 'answers.sign_mentor_name' => ['required', 'string'], 'answers.sign_mentor_date' => ['required', 'date_format:Y-m-d', 'before_or_equal:today']]);
            }
            $history = $t->submissions ?? [];
            if ($knowledge) {
                $history[] = ['version' => count($history) + 1, 'submitted_at' => null, 'answers' => [], 'template_hash' => $t->template['sha256'], 'evidence' => [], 'decision' => null];
            }
            $index = count($history) - 1;
            if (! $knowledge && ($index < 0 || ! array_key_exists('decision', $history[$index])
                || $history[$index]['decision'] !== null || ($history[$index]['template_hash'] ?? null) !== ($t->template['sha256'] ?? null))) {
                throw ValidationException::withMessages(['decision' => 'The locked submission needs checking before it can be marked.']);
            }
            $history[$index]['decision'] = ['status' => $r->input('decision'), 'tutor_id' => $r->user()->id, 'tutor_name' => $r->user()->name, 'date' => $r->input('decision_date'), 'feedback' => $r->input('feedback'), 'recorded_at' => now()->toIso8601String(), 'source_fields' => $evidence];
            $t->submissions = $history;
            $t->status = $r->input('decision');
            $t->version++;
            $t->save();
            $this->audit($r, $t->apprentice_id, $t->id, 'decision_recorded');
        });

        if ($r->input('after_save') === 'next') {
            $filters = MarkingQueueViews::parameters($r->input('queue', []));
            $next = MarkingQueueViews::next($r->user(), $filters, $task->id);
            if (! $next) {
                return redirect()->route('marking.index', $filters)->with('status', 'Decision saved. No further submissions in this view.');
            }

            return redirect()->route('tasks.show', ['task' => $next, 'from_marking' => 1] + $filters)
                ->with('status', 'Decision saved.');
        }

        return back()->with('status', 'Whole-task decision saved.');
    }

    public function deadline(Request $r, LearningTask $task): RedirectResponse
    {
        abort_unless(Access::manager($r->user()) || Access::tutor($r->user(), $task->apprentice), 403);
        $data = $r->validate(['version' => ['required', 'integer'], 'due_date' => ['nullable', 'date_format:Y-m-d'], 'reason' => ['nullable', 'string', 'max:2000']]);
        DB::transaction(function () use ($r, $task, $data): void {
            $t = LearningTask::whereKey($task->id)->lockForUpdate()->firstOrFail();
            $this->version($r, $t);
            $a = Apprentice::whereKey($t->apprentice_id)->lockForUpdate()->firstOrFail();
            $actor = TaskDeadlineHistory::actor($r->user(), $r->session()->get('auth_version'), $a);
            if (! TaskDeadlineHistory::record($t, $data['due_date'] ?? null, $actor, 'manual', $data['reason'] ?? null)) {
                return;
            }
            $t->version++;
            $t->save();
            $this->audit($r, $t->apprentice_id, $t->id, 'deadline_saved');
        });

        return back()->with('status', 'Task deadline checked. Any date change was saved with its history; no other deadlines changed.');
    }

    public function upload(Request $r, LearningTask $task): RedirectResponse
    {
        abort_unless(Access::learner($r->user(), $task->apprentice), 403);
        $r->validate(['version' => ['required', 'integer'], 'file' => ['required', 'file', 'mimes:pdf,jpg,jpeg,png,docx', 'extensions:pdf,jpg,jpeg,png,docx', 'max:15360'], 'kind' => ['required', Rule::in(['supporting-evidence', 'inspection-checklist', 'workplace-photo'])], 'description' => ['required_if:kind,workplace-photo', 'nullable', 'string', 'max:5000']]);
        if ($r->input('kind') === 'workplace-photo') {
            $r->validate(['file' => ['mimes:jpg,jpeg,png', 'extensions:jpg,jpeg,png']]);
        }
        $entry = null;
        try {
            DB::transaction(function () use ($r, $task, &$entry): void {
                $t = LearningTask::whereKey($task->id)->lockForUpdate()->firstOrFail();
                $this->version($r, $t);
                BlockRelease::check($t);
                if (($t->template['kind'] ?? 'source-pending') === 'source-pending') {
                    throw ValidationException::withMessages(['file' => 'This task needs its programme source before evidence can be uploaded.']);
                }
                if (! $t->editable()) {
                    throw ValidationException::withMessages(['file' => 'The submitted version is locked.']);
                }
                $entry = PrivateEvidence::store($r->file('file'), 'task-evidence', $r->user()->id);
                TaskEvidence::create(['learning_task_id' => $t->id, 'uploaded_by' => $r->user()->id, ...array_intersect_key($entry, array_flip(['disk', 'path', 'name', 'mime', 'sha256', 'bytes'])), 'kind' => $r->input('kind'), 'description' => $r->input('description')]);
                $t->version++;
                $t->save();
                $this->audit($r, $t->apprentice_id, $t->id, 'evidence_added');
            });
        } catch (\Throwable $e) {
            if ($entry) {
                PrivateEvidence::delete($entry);
            }throw $e;
        }

        return back()->with('status', 'Evidence saved privately. Task approval remains a separate tutor decision.');
    }

    public function download(Request $r, LearningTask $task, TaskEvidence $evidence): StreamedResponse
    {
        abort_unless($evidence->learning_task_id === $task->id && Access::training($r->user(), $task->apprentice), 403);
        abort_unless(PrivateEvidence::exists($evidence->toArray()), 404);

        return PrivateEvidence::download($evidence->toArray());
    }

    public function recordedPdf(Request $r, LearningTask $task): Response
    {
        abort_unless(Access::training($r->user(), $task->apprentice), 403);

        abort_if($task->template['kind'] === 'source-pending', 404, 'Task source has not been loaded.');

        return PortalPdf::response('pdf.task', ['task' => $task->load(['apprentice', 'evidence'])], 'GTA-Task-'.$task->apprentice->reference.'-'.$task->reference.'-v'.$task->version);
    }

    public function pdf(Request $r, LearningTask $task): BinaryFileResponse
    {
        abort_unless(Access::training($r->user(), $task->apprentice), 403);
        $filename = $task->template['sourcePdfFilename'] ?? $task->template['filename'];
        abort_unless(str_ends_with($filename, '.pdf') && basename($filename) === $filename, 404);

        $path = resource_path('task-pdfs/'.$filename);
        abort_unless(is_file($path), 404);
        abort_unless(hash_file('sha256', $path) === ($task->template['sourceTemplateHash'] ?? $task->template['sha256']), 409, 'The source PDF version changed. Ask an administrator to check the source.');

        return response()->download($path, $filename, ['X-Content-Type-Options' => 'nosniff']);
    }

    private function version(Request $r, LearningTask $t): void
    {
        if ($t->version !== $r->integer('version')) {
            throw ValidationException::withMessages(['version' => 'This task changed. Reopen before saving.']);
        }
    }

    private function answers(Request $r, LearningTask $t, array $roles): array
    {
        $rules = [];
        foreach ($t->template['fields'] as $f) {
            if (! in_array($f['role'], $roles, true) || in_array($f['type'], ['notice', 'decision'], true)) {
                continue;
            }
            $rule = match ($f['type']) {
                'date' => ['nullable', 'date_format:Y-m-d'],'score' => ['nullable', 'integer', 'between:1,10'],'number' => ['nullable', 'numeric', 'min:0'],'choice' => ['nullable', Rule::in($f['options'])],'checkbox' => ['nullable', Rule::in(['on', '1'])],default => ['nullable', 'string']
            };
            $rules['answers.'.$f['name']] = $rule;
        }$valid = $r->validate($rules);
        $answers = $valid['answers'] ?? [];
        foreach ($t->template['exclusiveCheckboxGroups'] ?? [] as $group) {
            if (collect($group)->filter(fn ($name) => in_array($answers[$name] ?? null, ['on', '1'], true))->count() > 1) {
                throw ValidationException::withMessages(['answers' => 'Choose only one status for each inspection item. Done and N/A cannot both be selected.']);
            }
        }

        return $answers;
    }

    private function audit(Request $r, int $a, int $id, string $event): void
    {
        DB::table('learning_events')->insert(['user_id' => $r->user()->id, 'apprentice_id' => $a, 'kind' => 'task', 'record_id' => $id, 'event' => $event, 'created_at' => now()]);
    }
}

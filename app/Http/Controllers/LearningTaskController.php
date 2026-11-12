<?php

namespace App\Http\Controllers;

use App\Models\Apprentice;
use App\Models\LearningTask;
use App\Models\TaskEvidence;
use App\Support\LearningAccess as Access;
use App\Support\PrivateEvidence;
use App\Support\TaskCatalogue;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
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
        if (in_array($r->query('status'), ['Draft', 'Awaiting marking', 'Referred', 'Approved'], true)) {
            $q->where('status', $r->query('status'));
        }

        return view('learning.index', ['apprentice' => $apprentice, 'tasks' => $q->orderBy('block')->orderBy('reference')->get(), 'manage' => Access::manager($r->user()), 'counts' => LearningTask::where('apprentice_id', $apprentice->id)->selectRaw('status, COUNT(*) AS total')->groupBy('status')->pluck('total', 'status')]);
    }

    public function allocate(Request $r, Apprentice $apprentice): RedirectResponse
    {
        abort_unless(Access::manager($r->user()), 403);
        $r->validate(['confirmed' => ['accepted']]);
        DB::transaction(function () use ($r, $apprentice): void {
            Apprentice::whereKey($apprentice->id)->lockForUpdate()->firstOrFail();
            if (LearningTask::where('apprentice_id', $apprentice->id)->exists()) {
                throw ValidationException::withMessages(['confirmed' => 'Tasks are already assigned. No duplicate allocation was made.']);
            }
            foreach (TaskCatalogue::tasks() as $t) {
                LearningTask::create(['apprentice_id' => $apprentice->id, 'reference' => $t['id'], 'block' => $t['block'], 'title' => $t['title'], 'theme' => $t['theme'], 'template' => TaskCatalogue::template($t['id'])]);
            }
            $this->audit($r, $apprentice->id, 0, 'allocated');
        });

        return back()->with('status', 'Assigned 50 Autocare source tasks. Dates and individual plan approval remain separate.');
    }

    public function show(Request $r, LearningTask $task): View
    {
        abort_unless(Access::training($r->user(), $task->apprentice), 403);

        return view('learning.task', ['planning' => collect(json_decode(file_get_contents(resource_path('data/training-plan-reference.json')), true, 512, JSON_THROW_ON_ERROR)['curriculumAmendment']['block9Planning']['activities'])->where('taskId', $task->reference), 'task' => $task->load('evidence'), 'apprentice' => $task->apprentice, 'tutor' => Access::tutor($r->user(), $task->apprentice) || Access::manager($r->user()), 'learner' => Access::learner($r->user(), $task->apprentice)]);
    }

    public function save(Request $r, LearningTask $task): RedirectResponse
    {
        abort_unless(Access::learner($r->user(), $task->apprentice), 403);
        $r->validate(['version' => ['required', 'integer'], 'intent' => ['required', Rule::in(['draft', 'submit'])], 'own_work' => ['sometimes', 'accepted']]);
        DB::transaction(function () use ($r, $task): void {
            $t = LearningTask::whereKey($task->id)->lockForUpdate()->firstOrFail();
            $this->version($r, $t);
            if (! $t->editable() || $t->template['kind'] === 'knowledge') {
                throw ValidationException::withMessages(['intent' => 'This task cannot be edited or submitted in its current state.']);
            }
            $answers = $this->answers($r, $t, ['apprentice']);
            if ($r->input('intent') === 'submit') {
                $r->validate(['own_work' => ['accepted']]);
                $names = array_filter($t->template['fields'], fn ($f) => $f['role'] === 'apprentice' && in_array($f['key'], ['apprentice_name', 'sign_apprentice_name', 'apprentice_signoff'], true));
                if (! collect($names)->contains(fn ($f) => filled($answers[$f['name']] ?? null))) {
                    throw ValidationException::withMessages(['answers' => 'Enter the apprentice name before submitting.']);
                }
                if ($t->template['kind'] === 'impact') {
                    foreach ($t->template['fields'] as $f) {
                        if ($f['type'] === 'score' && ! filled($answers[$f['name']] ?? null)) {
                            throw ValidationException::withMessages(['answers' => 'Complete all START and END scores, or save a draft.']);
                        }
                    }
                }
                foreach ($t->template['adasFieldNames'] ?? [] as $name) {
                    if (! filled($answers[$name] ?? null)) {
                        throw ValidationException::withMessages(['answers.'.$name => 'Answer both released ADAS questions before submitting, or save a draft.']);
                    }
                }
                if ($t->reference === 'b1-t3' && ! $t->evidence()->where('kind', 'inspection-checklist')->exists()) {
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

        return back()->with('status', $r->input('intent') === 'submit' ? 'Submitted to the tutor. This version is locked.' : 'Draft saved.');
    }

    public function decide(Request $r, LearningTask $task): RedirectResponse
    {
        abort_unless(Access::tutor($r->user(), $task->apprentice) || Access::manager($r->user()), 403);
        $r->validate(['version' => ['required', 'integer'], 'decision' => ['required', Rule::in(['Approved', 'Referred'])], 'decision_date' => ['required', 'date_format:Y-m-d', 'before_or_equal:today'], 'feedback' => ['nullable', 'required_if:decision,Referred', 'string', 'max:20000'], 'confirmed' => ['accepted']]);
        DB::transaction(function () use ($r, $task): void {
            $t = LearningTask::whereKey($task->id)->lockForUpdate()->firstOrFail();
            $this->version($r, $t);
            $knowledge = $t->template['kind'] === 'knowledge';
            if ((! $knowledge && $t->status !== 'Awaiting marking') || ($knowledge && ! $t->editable())) {
                throw ValidationException::withMessages(['decision' => 'Only the current awaiting submission can receive a decision.']);
            }
            if (! $knowledge && $t->submitted_at && $r->input('decision_date') < $t->submitted_at->toDateString()) {
                throw ValidationException::withMessages(['decision_date' => 'A tutor decision cannot precede the submitted work.']);
            }
            $evidence = $this->answers($r, $t, ['trainer', 'observer']);
            if ($knowledge) {
                $r->validate(['test_title' => ['required', 'string', 'max:500'], 'marks_available' => ['required', 'numeric', 'gt:0'], 'marks_achieved' => ['required', 'numeric', 'min:0', 'lte:marks_available']]);
                $evidence = [...$evidence, ...$r->only('test_title', 'marks_available', 'marks_achieved')];
            }
            if ($t->template['kind'] === 'jobcard' && $r->input('decision') === 'Approved') {
                $r->validate(['answers.mentor_confirmation' => ['accepted'], 'answers.sign_mentor_name' => ['required', 'string'], 'answers.sign_mentor_date' => ['required', 'date_format:Y-m-d', 'before_or_equal:today']]);
            }
            $history = $t->submissions ?? [];
            if ($knowledge) {
                $history[] = ['version' => count($history) + 1, 'submitted_at' => null, 'answers' => [], 'template_hash' => $t->template['sha256'], 'evidence' => [], 'decision' => null];
            }
            $index = count($history) - 1;
            $history[$index]['decision'] = ['status' => $r->input('decision'), 'tutor_id' => $r->user()->id, 'tutor_name' => $r->user()->name, 'date' => $r->input('decision_date'), 'feedback' => $r->input('feedback'), 'recorded_at' => now()->toIso8601String(), 'source_fields' => $evidence];
            $t->submissions = $history;
            $t->status = $r->input('decision');
            $t->version++;
            $t->save();
            $this->audit($r, $t->apprentice_id, $t->id, 'decision_recorded');
        });

        return back()->with('status', 'Whole-task decision saved. This is not overall apprenticeship or KSB sign-off.');
    }

    public function deadline(Request $r, LearningTask $task): RedirectResponse
    {
        abort_unless(Access::manager($r->user()) || Access::tutor($r->user(), $task->apprentice), 403);
        $data = $r->validate(['version' => ['required', 'integer'], 'due_date' => ['nullable', 'date_format:Y-m-d']]);
        DB::transaction(function () use ($r, $task, $data): void {
            $t = LearningTask::whereKey($task->id)->lockForUpdate()->firstOrFail();
            $this->version($r, $t);
            $t->due_date = $data['due_date'] ?? null;
            $t->version++;
            $t->save();
            $this->audit($r, $t->apprentice_id, $t->id, 'deadline_saved');
        });

        return back()->with('status', 'Task deadline saved. No other deadlines changed.');
    }

    public function upload(Request $r, LearningTask $task): RedirectResponse
    {
        abort_unless(Access::learner($r->user(), $task->apprentice), 403);
        $r->validate(['version' => ['required', 'integer'], 'file' => ['required', 'file', 'mimes:pdf,jpg,jpeg,png,docx', 'extensions:pdf,jpg,jpeg,png,docx', 'max:15360'], 'kind' => ['required', Rule::in(['supporting-evidence', 'inspection-checklist'])]]);
        $path = null;
        try {
            DB::transaction(function () use ($r, $task, &$path): void {
                $t = LearningTask::whereKey($task->id)->lockForUpdate()->firstOrFail();
                $this->version($r, $t);
                if (! $t->editable()) {
                    throw ValidationException::withMessages(['file' => 'The submitted version is locked.']);
                }
                $path = $r->file('file')->store('task-evidence', PrivateEvidence::diskName());
                if (! $path) {
                    throw new \RuntimeException('Evidence could not be stored.');
                }
                TaskEvidence::create(['learning_task_id' => $t->id, 'uploaded_by' => $r->user()->id, 'path' => $path, 'name' => mb_substr(basename(str_replace('\\', '/', $r->file('file')->getClientOriginalName())), 0, 255), 'mime' => $r->file('file')->getMimeType(), 'sha256' => hash_file('sha256', $r->file('file')->getRealPath()), 'bytes' => $r->file('file')->getSize(), 'kind' => $r->input('kind')]);
                $t->version++;
                $t->save();
                $this->audit($r, $t->apprentice_id, $t->id, 'evidence_added');
            });
        } catch (\Throwable $e) {
            if ($path) {
                PrivateEvidence::delete($path);
            }throw $e;
        }

        return back()->with('status', 'Evidence saved privately. Task approval remains a separate tutor decision.');
    }

    public function download(Request $r, LearningTask $task, TaskEvidence $evidence): StreamedResponse
    {
        abort_unless($evidence->learning_task_id === $task->id && Access::training($r->user(), $task->apprentice), 403);
        abort_unless(PrivateEvidence::exists($evidence->path), 404);

        return PrivateEvidence::download($evidence->path, $evidence->name);
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
                'date' => ['nullable', 'date_format:Y-m-d'],'score' => ['nullable', 'integer', 'between:1,10'],'number' => ['nullable', 'numeric', 'min:0'],'choice' => ['nullable', Rule::in($f['options'])],'checkbox' => ['nullable', Rule::in(['on', '1'])],default => ['nullable', 'string', 'max:50000']
            };
            $rules['answers.'.$f['name']] = $rule;
        }$valid = $r->validate($rules);

        return $valid['answers'] ?? [];
    }

    private function audit(Request $r, int $a, int $id, string $event): void
    {
        DB::table('learning_events')->insert(['user_id' => $r->user()->id, 'apprentice_id' => $a, 'kind' => 'task', 'record_id' => $id, 'event' => $event, 'created_at' => now()]);
    }
}

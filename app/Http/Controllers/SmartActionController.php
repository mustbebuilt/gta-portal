<?php

namespace App\Http\Controllers;

use App\Models\Apprentice;
use App\Models\LearningTask;
use App\Models\SmartAction;
use App\Support\LearningAccess as Access;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class SmartActionController extends Controller
{
    private function manage(Request $r, Apprentice $a): bool
    {
        return Access::manager($r->user()) || Access::tutor($r->user(), $a);
    }

    public function index(Request $r, Apprentice $apprentice): View
    {
        abort_unless(Access::training($r->user(), $apprentice), 403);
        $actions = SmartAction::with('task')->where('apprentice_id', $apprentice->id)->orderBy('created_at')->get();
        $filtered = $actions->filter(fn ($a) => (! $r->query('owner') || $a->owner === $r->query('owner')) && (! $r->query('state') || $a->currentStatus() === $r->query('state')) && match ($r->query('due')) {
            'overdue' => $a->overdue(),'today' => $a->deadline()?->isToday() && $a->currentStatus() !== 'Completed','checks' => ! $a->deadline(),default => true
        });

        return view('actions.index', ['apprentice' => $apprentice, 'actions' => $filtered, 'all' => $actions, 'manage' => $this->manage($r, $apprentice)]);
    }

    public function form(Request $r, Apprentice $apprentice, ?SmartAction $action = null): View
    {
        abort_unless($this->manage($r, $apprentice), 403);
        if ($action) {
            abort_unless($action->apprentice_id === $apprentice->id, 404);
        }

        return view('actions.form', ['apprentice' => $apprentice, 'action' => $action ?? new SmartAction, 'tasks' => LearningTask::where('apprentice_id', $apprentice->id)->where('status', 'Referred')->get()]);
    }

    public function save(Request $r, Apprentice $apprentice, ?SmartAction $action = null): RedirectResponse
    {
        abort_unless($this->manage($r, $apprentice), 403);
        if ($action) {
            abort_unless($action->apprentice_id === $apprentice->id, 404);
        }
        $data = $r->validate(['title' => ['required', 'string', 'max:2000'], 'owner' => ['required', Rule::in(SmartAction::OWNERS)], 'due_date' => ['nullable', 'date_format:Y-m-d'], 'method' => ['required', Rule::in(['quantity', 'outcomes', 'linked-task'])], 'quantity' => ['nullable', 'required_if:method,quantity', 'integer', 'min:1'], 'unit' => ['nullable', 'required_if:method,quantity', Rule::in(['questions', 'exercises', 'worksheets', 'tasks', 'jobcards', 'evidence', 'other'])], 'custom_unit' => ['nullable', 'required_if:unit,other', 'string', 'max:100'], 'criteria' => ['nullable', 'required_if:method,outcomes', 'string', 'max:5000'], 'observable' => ['exclude_unless:method,outcomes', 'accepted'], 'evidence_method' => ['required', Rule::in(['portal', 'worksheet', 'observation', 'discussion', 'other'])], 'relevance' => ['required', Rule::in(['block', 'englishmaths', 'correction', 'review', 'workplace', 'other'])], 'learning_reference' => ['required', 'string', 'max:250'], 'support' => ['required', 'string', 'max:2000'], 'achievable' => ['accepted'], 'agreed' => ['accepted'], 'learning_task_id' => ['nullable', 'required_if:method,linked-task', 'integer'], 'version' => $action ? ['required', 'integer'] : ['prohibited']]);
        DB::transaction(function () use ($r, $apprentice, $action, $data): void {
            Apprentice::whereKey($apprentice->id)->lockForUpdate()->firstOrFail();
            $a = $action ? SmartAction::whereKey($action->id)->lockForUpdate()->firstOrFail() : new SmartAction(['apprentice_id' => $apprentice->id, 'created_by' => $r->user()->id]);
            if ($a->exists) {
                $this->version($r, $a);
                if ($a->currentStatus() !== 'To do') {
                    throw ValidationException::withMessages(['title' => 'Reopen an action in To do before changing its target.']);
                }
            }
            $linked = null;
            if ($data['method'] === 'linked-task') {
                $linked = LearningTask::where('apprentice_id', $apprentice->id)->find($data['learning_task_id']);
                if (! $linked || (! $a->exists && $linked->status !== 'Referred')) {
                    throw ValidationException::withMessages(['learning_task_id' => 'Choose a referred task belonging to this apprentice.']);
                }if (SmartAction::where('apprentice_id', $apprentice->id)->where('learning_task_id', $linked->id)->when($a->exists, fn ($q) => $q->where('id', '!=', $a->id))->exists()) {
                    throw ValidationException::withMessages(['learning_task_id' => 'This task already has an action. Open the existing action.']);
                }
            }
            if ($a->exists && $a->learning_task_id && (! $linked || $linked->id !== $a->learning_task_id)) {
                throw ValidationException::withMessages(['learning_task_id' => 'An existing linked action keeps its original task.']);
            }
            if (! $linked) {
                $r->validate(['due_date' => ['required', 'date_format:Y-m-d', Rule::when(! $a->exists || $a->due_date?->toDateString() !== ($data['due_date'] ?? null), 'after_or_equal:today')]]);
            }
            $measure = match ($data['method']) {
                'quantity' => $data['quantity'].' '.($data['unit'] === 'other' ? $data['custom_unit'] : $data['unit']),'outcomes' => $data['criteria'],default => 'Approval of linked task '.$linked->reference
            };
            $history = $a->history ?? [];
            $history[] = ['event' => 'target_saved', 'title' => $data['title'], 'measure' => $measure, 'due_date' => $linked ? $linked->due_date?->toDateString() : ($data['due_date'] ?? null), 'by' => $r->user()->id, 'at' => now()->toIso8601String()];
            $a->fill(collect($data)->only('title', 'owner', 'method', 'evidence_method', 'relevance', 'learning_reference', 'support')->all());
            $a->learning_task_id = $linked?->id;
            $a->due_date = $linked ? null : ($data['due_date'] ?? null);
            $a->measure = $measure;
            $a->smart_data = collect($data)->only('quantity', 'unit', 'custom_unit', 'criteria', 'observable', 'achievable', 'agreed')->all();
            $a->history = $history;
            if ($a->exists) {
                $a->version++;
            }$a->save();
            $this->audit($r, $a, 'target_saved');
        });

        return to_route('actions.index', $apprentice)->with('status', 'SMART action saved. Linked tasks retain their own deadlines and tutor decisions.');
    }

    public function progress(Request $r, SmartAction $action): RedirectResponse
    {
        abort_unless(Access::training($r->user(), $action->apprentice), 403);
        $manage = $this->manage($r, $action->apprentice);
        $data = $r->validate(['version' => ['required', 'integer'], 'intent' => ['required', Rule::in(['submit', 'complete', 'return'])], 'evidence_note' => ['nullable', 'string', 'max:20000'], 'feedback' => ['nullable', 'string', 'max:20000'], 'completion_date' => ['nullable', 'date_format:Y-m-d', 'before_or_equal:today']]);
        DB::transaction(function () use ($r, $action, $data, $manage): void {
            $a = SmartAction::whereKey($action->id)->lockForUpdate()->firstOrFail();
            $this->version($r, $a);
            if ($a->learning_task_id) {
                throw ValidationException::withMessages(['intent' => 'Open the linked task to submit work or record a tutor decision.']);
            }
            if ($data['intent'] === 'submit') {
                abort_unless(Access::learner($r->user(), $a->apprentice) && $a->owner === 'Apprentice', 403);
                if ($a->status !== 'To do') {
                    throw ValidationException::withMessages(['intent' => 'Only actions in To do can be submitted.']);
                }$r->validate(['evidence_note' => ['required', 'string']]);
                $a->evidence_note = $data['evidence_note'];
                $a->status = 'With tutor';
            } else {
                abort_unless($manage, 403);
                if ($a->status === 'Completed') {
                    throw ValidationException::withMessages(['intent' => 'This action is already completed.']);
                }if ($data['intent'] === 'return') {
                    $r->validate(['feedback' => ['required', 'string']]);
                    $a->status = 'To do';
                    $a->completion_date = null;
                } else {
                    $r->validate(['completion_date' => ['required', 'date_format:Y-m-d', 'before_or_equal:today'], 'feedback' => ['required', 'string']]);
                    if ($a->owner === 'Apprentice' && $a->status !== 'With tutor') {
                        throw ValidationException::withMessages(['intent' => 'The apprentice must submit completion evidence first.']);
                    }$a->status = 'Completed';
                    $a->completion_date = $data['completion_date'];
                }$a->feedback = $data['feedback'];
            }
            $history = $a->history ?? [];
            $history[] = ['event' => $data['intent'], 'status' => $a->status, 'evidence_note' => $a->evidence_note, 'feedback' => $a->feedback, 'by' => $r->user()->id, 'at' => now()->toIso8601String()];
            $a->history = $history;
            $a->version++;
            $a->save();
            $this->audit($r, $a, $data['intent']);
        });

        return back()->with('status', 'Action progress saved.');
    }

    private function version(Request $r, SmartAction $a): void
    {
        if ($a->version !== $r->integer('version')) {
            throw ValidationException::withMessages(['version' => 'This action changed. Reopen before saving.']);
        }
    }

    private function audit(Request $r, SmartAction $a, string $event): void
    {
        DB::table('learning_events')->insert(['user_id' => $r->user()->id, 'apprentice_id' => $a->apprentice_id, 'kind' => 'action', 'record_id' => $a->id, 'event' => $event, 'created_at' => now()]);
    }
}

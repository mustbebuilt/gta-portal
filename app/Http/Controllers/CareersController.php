<?php

namespace App\Http\Controllers;

use App\Models\Apprentice;
use App\Models\CareerDiscussion;
use App\Models\LearningBlockControl;
use App\Models\LearningTask;
use App\Models\User;
use App\Support\BlockRelease;
use App\Support\CareersFollowup;
use App\Support\LearningAccess as Access;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class CareersController extends Controller
{
    private function write(Request $r, Apprentice $a): bool
    {
        return Access::manager($r->user()) || \App\Support\StaffDuties::assigned($r->user(), $a, 'careers_adviser');
    }

    private function viewable(Request $r, Apprentice $a): bool
    {
        return $this->write($r, $a) || Access::learner($r->user(), $a);
    }

    public function worklist(Request $r): View
    {
        abort_unless(Access::manager($r->user()) || ($r->user()->active && $r->user()->role==='apprentice') || \App\Support\StaffDuties::has($r->user(),'careers_adviser'), 403);
        $data = $r->validate(['search' => ['nullable', 'string', 'max:100'], 'show' => ['nullable', Rule::in(CareersFollowup::VIEWS)], 'type' => ['nullable', Rule::in(['all', 'employment', 'progression'])], 'action_page' => ['nullable', 'integer', 'min:1'], 'apprentice_page' => ['nullable', 'integer', 'min:1']]);
        $q = Apprentice::with('careersAdviser');
        if (! Access::manager($r->user())) {
            $q->where($r->user()->role === 'apprentice' ? 'user_id' : 'careers_adviser_id', $r->user()->id);
        }

        $apprentices = $q->orderBy('last_name')->orderBy('first_name')->orderBy('id')->get();
        $search = trim($data['search'] ?? '');
        if ($search !== '') {
            $apprentices = $apprentices->filter(fn ($a) => Str::contains(Str::lower($a->first_name.' '.$a->last_name.' '.$a->reference), Str::lower($search)));
        }
        $show = $data['show'] ?? 'all'; $type = $data['type'] ?? 'all';
        $discussions = $r->user()->role === 'apprentice' ? collect() : CareerDiscussion::whereIn('apprentice_id', $apprentices->pluck('id'))->when($type !== 'all', fn ($query) => $query->where('type', $type))->get(['id', 'apprentice_id', 'type', 'actions']);
        $followup = CareersFollowup::for($apprentices, $discussions);
        $all = $followup['rows'];
        $items = CareersFollowup::paginate($all->filter(fn ($item) => $show === 'all' || $item['flags'][$show])->values(), $r, 'action_page');

        $blockPrompts = [];
        if ($r->user()->role !== 'apprentice') {
            $controls = LearningBlockControl::whereIn('apprentice_id', $apprentices->pluck('id'))->where('enabled', true)->pluck('apprentice_id')->all();
            $blockTasks = LearningTask::whereIn('apprentice_id', $controls)->where('block', 10)->orderBy('id')->get()->groupBy('apprentice_id');
            foreach ($apprentices as $a) {
                $task = $blockTasks->get($a->id)?->first();
                if ($task && BlockRelease::state($task)['open']) {
                    $blockPrompts[] = ['apprentice' => $a, 'discussion' => CareerDiscussion::where('apprentice_id', $a->id)->where('type', 'progression')->orderByDesc('discussion_date')->first()];
                }
            }
        }

        return view('careers.worklist', ['plannedPrompts'=>$r->user()->role==='apprentice' ? [] : \App\Support\CareersPrompts::worklist($apprentices),'apprentices'=>CareersFollowup::paginate($apprentices->values(), $r, 'apprentice_page'),'search'=>$search,'workCounts'=>$followup['counts'],'sourceChecks'=>$followup['sourceChecks'],'items'=>$items,'all'=>$all,'show'=>$show,'type'=>$type,'blockPrompts'=>$blockPrompts]);
    }

    public function recordPrompts(Request $r): RedirectResponse
    {
        abort_unless(Access::manager($r->user()),403);
        $counts=\App\Support\CareersPrompts::generate(true,$r->user());
        return back()->with('status',$counts['created'].' new in-portal Careers prompts recorded. No advice, email or completion recorded.');
    }

    public function seenPrompt(Request $r, \App\Models\CareersPrompt $prompt): RedirectResponse
    {
        \App\Support\CareersPrompts::seen($prompt,$r->user());
        return back()->with('status','Prompt marked seen. Record any delivered advice in the original Careers discussion.');
    }

    public function index(Request $r, Apprentice $apprentice): View
    {
        abort_unless($this->viewable($r, $apprentice), 403);

        $data = $r->validate(['edit' => ['nullable', 'integer', 'min:1'], 'discussion' => ['nullable', 'integer', 'min:1'], 'history_type' => ['nullable', Rule::in(['all', 'employment', 'progression'])], 'history_page' => ['nullable', 'integer', 'min:1']]);
        if (isset($data['edit'], $data['discussion']) && (int) $data['edit'] !== (int) $data['discussion']) {
            throw ValidationException::withMessages(['discussion' => 'Open one source discussion before amending it.']);
        }
        $editing = isset($data['edit']) ? CareerDiscussion::where('apprentice_id', $apprentice->id)->findOrFail($data['edit']) : null;
        abort_unless(! $editing || $this->write($r, $apprentice), 403);
        $pinned = $data['discussion'] ?? $editing?->id;
        $query = CareerDiscussion::with('adviser')->where('apprentice_id', $apprentice->id);
        if ($pinned) {
            $query->whereKey($pinned);
            abort_unless((clone $query)->exists(), 404);
        } elseif (($data['history_type'] ?? 'all') !== 'all') {
            $query->where('type', $data['history_type']);
        }
        $discussions = CareersFollowup::paginate($query->orderByDesc('discussion_date')->orderByDesc('id')->get(), $r, 'history_page', 10);
        foreach ($discussions as $d) {
            if (! CareersFollowup::actionsReadable($d->actions)) {
                if ($r->expectsJson()) {
                    abort(422, 'Careers action source needs administrator reconciliation before this discussion can be opened. No record has been changed.');
                }
                throw new \Illuminate\Http\Exceptions\HttpResponseException(response()->view('careers.source-check', ['apprentice' => $apprentice, 'discussionId' => $d->id], 422));
            }
        }
        return view('careers.index', ['apprentice' => $apprentice, 'editing' => $editing, 'write' => $this->write($r, $apprentice), 'discussions' => $discussions, 'pinnedDiscussion' => $pinned]);
    }

    public function save(Request $r, Apprentice $apprentice, ?CareerDiscussion $discussion = null): RedirectResponse
    {
        abort_unless($this->write($r, $apprentice), 403);
        abort_unless(! $discussion || $discussion->apprentice_id === $apprentice->id, 404);
        $adviser = User::find($apprentice->careers_adviser_id);
        if (! $adviser || ! \App\Support\StaffDuties::has($adviser, 'careers_adviser')) {
            throw ValidationException::withMessages(['adviser' => 'Assign an active careers adviser in the apprentice profile first.']);
        }
        $data = $r->validate(['version' => $discussion ? ['required', 'integer'] : ['prohibited'], 'type' => ['required', Rule::in(['progression', 'employment'])], 'discussion_date' => ['required', 'date_format:Y-m-d', 'before_or_equal:today'], 'goals' => ['required', 'string', 'max:10000'], 'options' => ['required', 'string', 'max:10000'], 'advice' => ['required', 'string', 'max:20000'], 'outcome' => ['required', Rule::in(['Next step agreed', 'Exploring options', 'Specialist referral'])], 'next_step' => ['required', 'string', 'max:10000'], 'referral' => ['nullable', 'required_if:outcome,Specialist referral', 'string', 'max:250']]);
        DB::transaction(function () use ($r, $apprentice, $adviser, $data, $discussion): void {
            $a = Apprentice::whereKey($apprentice->id)->lockForUpdate()->firstOrFail();
            abort_unless($this->write($r, $a), 403);
            $adviser = User::whereKey($a->careers_adviser_id)->lockForUpdate()->first();
            if (! $adviser || ! \App\Support\StaffDuties::has($adviser, 'careers_adviser')) {
                throw ValidationException::withMessages(['adviser'=>'The assigned Careers duty changed. Reopen the apprentice record.']);
            }
            $fields = collect($data)->except('version')->all();
            $fields['referral'] = $data['outcome'] === 'Specialist referral' ? ($data['referral'] ?? null) : null;
            if ($discussion) {
                $d = CareerDiscussion::whereKey($discussion->id)->lockForUpdate()->firstOrFail();
                $this->version($r, $d);
                if (collect($d->followups ?? [])->contains(fn ($f) => $f['date'] < $data['discussion_date'])) {
                    throw ValidationException::withMessages(['discussion_date' => 'The discussion date cannot be after a recorded follow-up.']);
                }
                $h = $d->history ?? [];
                $h[] = ['event' => 'Discussion amended', 'previous' => $d->only(array_keys($fields)), 'by' => $r->user()->id, 'at' => now()->toIso8601String()];
                $d->fill($fields);
                $d->history = $h;
                $d->version++;
                $d->save();
            } else {
                $d = CareerDiscussion::create([...$fields, 'apprentice_id' => $a->id, 'adviser_id' => $adviser->id, 'recorded_by' => $r->user()->id]);
            }
            $this->audit($r, $d, $discussion ? 'discussion_amended' : 'discussion_saved');
        });

        return to_route('careers.index', $apprentice)->with('status', 'Careers discussion saved privately. Original adviser attribution and history are retained.');
    }

    public function action(Request $r, CareerDiscussion $discussion): RedirectResponse
    {
        abort_unless($this->write($r, $discussion->apprentice), 403);
        $data = $r->validate(['action_id' => ['nullable', 'string'], 'version' => ['required', 'integer'], 'title' => ['required', 'string', 'max:2000'], 'owner' => ['required', Rule::in(['Apprentice', 'Careers adviser'])], 'due_date' => ['required', 'date_format:Y-m-d', 'after_or_equal:today'], 'criteria' => ['required', 'string', 'max:5000'], 'support' => ['required', 'string', 'max:2000'], 'observable' => ['accepted'], 'achievable' => ['accepted'], 'agreed' => ['accepted']]);
        DB::transaction(function () use ($r, $discussion, $data): void {
            $d = CareerDiscussion::whereKey($discussion->id)->lockForUpdate()->firstOrFail();
            $this->version($r, $d);
            $a = $d->actions ?? [];
            $index = null;
            foreach ($a as $key => $item) {
                if ($item['id'] === ($data['action_id'] ?? null)) {
                    $index = $key;
                }
            }
            if (filled($data['action_id'] ?? null) && ($index === null || $a[$index]['status'] !== 'To do')) {
                throw ValidationException::withMessages(['action_id' => 'Only a To do action from this discussion can be revised.']);
            }
            $new = ['id' => $index === null ? (string) Str::uuid() : $a[$index]['id'], 'title' => $data['title'], 'owner' => $data['owner'], 'due_date' => $data['due_date'], 'criteria' => $data['criteria'], 'support' => $data['support'], 'status' => 'To do', 'agreed_at' => today()->toDateString(), 'created_by' => $index === null ? $r->user()->id : $a[$index]['created_by'], 'confirmed_smart' => true];
            if ($index === null) {
                $a[] = $new;
            } else {
                $h = $d->history ?? [];
                $h[] = ['event' => 'SMART action revised', 'previous' => $a[$index], 'by' => $r->user()->id, 'at' => now()->toIso8601String()];
                $d->history = $h;
                $a[$index] = $new;
            }
            $d->actions = $a;
            $d->version++;
            $d->save();
            $this->audit($r, $d, 'action_agreed');
        });

        return back()->with('status', 'Private SMART careers action saved. It is not copied to the shared Action Plan.');
    }

    public function followup(Request $r, CareerDiscussion $discussion): RedirectResponse
    {
        abort_unless($this->viewable($r, $discussion->apprentice), 403);
        $write = $this->write($r, $discussion->apprentice);
        $data = $r->validate(['version' => ['required', 'integer'], 'date' => ['required', 'date_format:Y-m-d', 'before_or_equal:today'], 'note' => ['required', 'string', 'max:10000'], 'impact' => ['required', Rule::in(['Decision made', 'Next step taken', 'More guidance needed', 'No progress yet'])], 'action_id' => ['nullable', 'string'], 'decision' => ['required', Rule::in(['Update only', 'Ready for adviser check', 'Confirm completed', 'Return for changes'])]]);
        DB::transaction(function () use ($r, $discussion, $data, $write): void {
            $d = CareerDiscussion::whereKey($discussion->id)->lockForUpdate()->firstOrFail();
            $this->version($r, $d);
            if ($data['date'] < $d->discussion_date->toDateString()) {
                throw ValidationException::withMessages(['date' => 'Follow-up cannot be before the discussion.']);
            }$actions = $d->actions ?? [];
            $index = null;
            foreach ($actions as $i => $a) {
                if ($a['id'] === ($data['action_id'] ?? null)) {
                    $index = $i;
                }
            }
            if (filled($data['action_id'] ?? null) && $index === null) {
                throw ValidationException::withMessages(['action_id' => 'Choose an action from this discussion.']);
            }
            if (! $write && ($index === null || $actions[$index]['owner'] !== 'Apprentice')) {
                abort(403);
            }if (! $write && in_array($data['decision'], ['Confirm completed', 'Return for changes'], true)) {
                abort(403);
            }
            if ($data['decision'] !== 'Update only' && $index === null) {
                throw ValidationException::withMessages(['action_id' => 'Choose an action for a status decision.']);
            }
            if ($index !== null) {
                if ($data['date'] < $actions[$index]['agreed_at']) {
                    throw ValidationException::withMessages(['date' => 'Follow-up cannot be before the action was agreed.']);
                }if ($data['decision'] !== 'Update only' && $actions[$index]['status'] === 'Completed') {
                    throw ValidationException::withMessages(['decision' => 'This action is already complete. Add an update note if needed.']);
                }if ($data['decision'] === 'Return for changes' && $actions[$index]['status'] !== 'Ready for adviser check') {
                    throw ValidationException::withMessages(['decision' => 'Only submitted actions can be returned.']);
                }
                if ($data['decision'] !== 'Update only') {
                    $actions[$index]['status'] = match ($data['decision']) {
                        'Ready for adviser check' => 'Ready for adviser check','Confirm completed' => 'Completed',default => 'To do'
                    };
                    if ($data['decision'] === 'Confirm completed') {
                        $actions[$index]['completed_at'] = $data['date'];
                        $actions[$index]['completed_by'] = $r->user()->id;
                    }
                }
            }
            $f = $d->followups ?? [];
            $f[] = [...collect($data)->except('version')->all(), 'by' => $r->user()->id, 'at' => now()->toIso8601String()];
            $d->actions = $actions;
            $d->followups = $f;
            $d->version++;
            $d->save();
            $this->audit($r, $d, 'followup_saved');
        });

        return back()->with('status', 'Careers follow-up saved.');
    }

    public function resources(Request $r): View
    {
        abort_unless($r->user()->role !== 'employer', 403);
        $source = json_decode(file_get_contents(resource_path('data/career-resources.json')), true);
        $resources = collect($source['resources'])->filter(function ($row) {
            $u = parse_url($row['url'] ?? '');

            return ($u['scheme'] ?? '') === 'https' && ! isset($u['user']) && ! isset($u['pass']);
        });

        return view('careers.resources', ['resources' => $resources, 'checked' => $source['checked']]);
    }

    private function version(Request $r, CareerDiscussion $d): void
    {
        if ($d->version !== $r->integer('version')) {
            throw ValidationException::withMessages(['version' => 'This careers record changed. Reopen before saving.']);
        }
    }

    private function audit(Request $r, CareerDiscussion $d, string $event): void
    {
        DB::table('learning_events')->insert(['user_id' => $r->user()->id, 'apprentice_id' => $d->apprentice_id, 'kind' => 'careers', 'record_id' => $d->id, 'event' => $event, 'created_at' => now()]);
    }
}

<?php

namespace App\Http\Controllers;

use App\Models\Apprentice;
use App\Models\SupportConcern;
use App\Models\SupportMarkerSchedule71;
use App\Models\SupportSharedAction71;
use App\Models\SupportPlan;
use App\Models\TeachingInstruction;
use App\Models\User;
use App\Support\EmployerAttendance;
use App\Support\ExecutiveAccess;
use App\Support\LearningAccess as Access;
use App\Support\MarkerHistory;
use App\Support\PortalPdf;
use App\Support\PrivateEvidence;
use App\Support\SupportAccess;
use App\Support\SupportFollowup;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\HttpKernel\Exception\HttpException;

class SupportController extends Controller
{
    private const PRIVATE_CACHE = ['Cache-Control'=>'private, no-store', 'Referrer-Policy'=>'same-origin'];
    private function team(Request $r, ?Apprentice $apprentice = null): bool
    {
        return SupportAccess::team($r->user(), $apprentice);
    }

    private function lead(Request $r, ?Apprentice $apprentice = null): bool
    {
        return SupportAccess::lead($r->user(), $apprentice);
    }

    private function raiseAllowed(Request $r, Apprentice $a): bool
    {
        return $this->team($r, $a) || Access::tutor($r->user(), $a) || Access::subject($r->user(), $a, 'maths') || Access::subject($r->user(), $a, 'english') || \App\Support\StaffDuties::assigned($r->user(),$a,'mentor') || \App\Support\StaffDuties::assigned($r->user(),$a,'careers_adviser');
    }

    private function codes(): array
    {
        return json_decode(file_get_contents(resource_path('data/inclusion-markers.json')), true, 512, JSON_THROW_ON_ERROR);
    }

    private function recipients(Apprentice $a): array
    {
        return User::whereIn('id', array_filter([$a->tutor_id, $a->maths_tutor_id, $a->english_tutor_id]))->where('active', true)->get()->filter(fn ($u) => Access::tutor($u, $a) || (\App\Support\StaffDuties::assigned($u,$a,'maths_tutor') || \App\Support\StaffDuties::assigned($u,$a,'english_tutor')))->pluck('id')->all();
    }

    public function worklist(Request $r): Response
    {
        abort_unless($this->team($r), 403);
        $data = $r->validate(['meeting'=>['nullable','integer','min:1'],'search' => ['nullable', 'string', 'max:100'], 'view' => ['nullable','in:all,needs-review'], 'by' => ['nullable','date_format:Y-m-d'], 'marker' => ['nullable',Rule::in(array_keys($this->codes()))], 'state' => ['nullable', Rule::in(SupportFollowup::CONCERN_VIEWS)], 'plan_state' => ['nullable', Rule::in(SupportFollowup::PLAN_VIEWS)], 'page' => ['nullable', 'integer', 'min:1'], 'plan_page' => ['nullable', 'integer', 'min:1'], 'apprentice_page' => ['nullable', 'integer', 'min:1']]);
        $search = trim($data['search'] ?? '');
        $allowedIds = SupportAccess::ids($r->user());
        $people = Apprentice::whereIn('id', $allowedIds)->with('groupRecord')->get();
        if ($search !== '') {
            $people = $people->filter(fn ($a) => Str::contains(Str::lower($a->first_name.' '.$a->last_name.' '.$a->reference), Str::lower($search)));
        }
        $concerns = SupportConcern::with(['apprentice', 'owner:id,name,role,active'])
            ->whereIn('apprentice_id', $people->pluck('id'))->oldest()->orderBy('id')
            ->get(['id', 'apprentice_id', 'owner_id', 'code', 'status', 'action_due', 'next_action', 'next_review', 'acknowledged_at', 'created_at', 'version']);
        $plans = SupportPlan::whereIn('apprentice_id', $people->pluck('id'))->get(['id', 'apprentice_id', 'status', 'active_plan', 'reviews', 'activities']);
        if (!$r->hasAny(['state', 'plan_state', 'plan_page', 'apprentice_page']) || $r->filled('view')) {
            $data['view'] ??= 'needs-review';
            $register=$this->registerData($r,$people,$concerns,$plans,$data);
            if (!empty($data['meeting'])) {
                $selected=$register['meetingRows']->first(fn($row)=>$row['person']->id===(int)$data['meeting']);
                $position=$register['meetingRows']->search(fn($row)=>$row['person']->id===(int)$data['meeting']);
                $next=$position===false ? $register['meetingRows']->first() : $register['meetingRows']->get($position+1);
                $record=$selected ? $this->recordData($r,$selected['person']) : [];
                return response()->view('support.meeting',$record+$register+['selected'=>$selected,'nextMeeting'=>$next,'search'=>$search],200,self::PRIVATE_CACHE);
            }
            return response()->view('support.register', $register + [
                'search' => $search,
                'employerMeeting' => $data['view'] === 'needs-review' ? $this->employerMeeting($allowedIds) : null,
            ], 200, self::PRIVATE_CACHE);
        }

        $state = $data['state'] ?? 'open';
        $planState = $data['plan_state'] ?? 'all';
        $concernSummary = SupportFollowup::concerns($concerns);
        $planSummary = SupportFollowup::plans($plans, $people);
        return response()->view('support.worklist', ['supportPeople' => SupportFollowup::paginate($people->sortBy(fn ($a) => Str::lower($a->last_name.' '.$a->first_name.' '.$a->reference))->values(), $r, 'apprentice_page'), 'concernRows' => SupportFollowup::paginate($concernSummary['rows']->filter(fn ($row) => $row['flags'][$state])->values(), $r, 'page'), 'concernCounts' => $concernSummary['counts'], 'planRows' => SupportFollowup::paginate($planSummary['rows']->filter(fn ($row) => $row['flags'][$planState])->values(), $r, 'plan_page'), 'planCounts' => $planSummary['counts'], 'search' => $search, 'state' => $state, 'planState' => $planState, 'codes' => $this->codes()], 200, self::PRIVATE_CACHE);
    }

    private function employerMeeting(array $allowedIds): array
    {
        $monday = CarbonImmutable::now('Europe/London')->startOfWeek(CarbonImmutable::MONDAY);
        $from = $monday->subWeeks(4)->toDateString();
        $through = $monday->subDay()->toDateString();

        try {
            [$rows, $employers] = EmployerAttendance::keptAtWork($from, $through, $allowedIds);
        } catch (HttpException $exception) {
            if ($exception->getStatusCode() !== 422) {
                throw $exception;
            }

            return ['available' => false, 'from' => $from, 'through' => $through, 'items' => collect()];
        }

        $items = EmployerAttendance::groups($rows, $employers)
            ->filter(fn ($group) => $group['employer'] && $group['apprentices'] >= 2);

        return ['available' => true, 'from' => $from, 'through' => $through, 'items' => $items];
    }

    private function registerData(Request $request, $people, $concerns, $plans, array $filters): array
    {
        $by = $filters['by'] ?? today()->toDateString();
        $ids = $people->pluck('id');
        $today = today()->toDateString();
        $schedules = SupportMarkerSchedule71::whereIn('apprentice_id', $ids)->get()->keyBy('support_concern_id');
        $shared = SupportSharedAction71::with('owner:id,name')->whereIn('apprentice_id', $ids)
            ->where('state', 'Open')->orderBy('due_date')->orderBy('id')->get()->groupBy('apprentice_id');
        $markers = DB::table('apprentice_markers')->whereIn('apprentice_id', $ids)
            ->get(['apprentice_id', 'code'])->groupBy('apprentice_id');
        $concerns = $concerns->groupBy('apprentice_id');
        $plans = $plans->groupBy('apprentice_id');
        $rows = $people->sortBy([['last_name','asc'], ['first_name','asc']])->map(function ($person) use ($concerns, $markers, $schedules, $shared, $plans, $by, $today) {
            $cases = $concerns->get($person->id, collect());
            $open = $cases->where('status', '!=', 'Closed');
            $codes = $open->pluck('code')->filter()->merge($markers->get($person->id, collect())->pluck('code'))->unique();
            $due = $open->filter(function ($marker) use ($schedules, $by) {
                $schedule = $schedules->get($marker->id);
                return $schedule
                    ? (!$schedule->first_review_completed || !$schedule->next_due_date || $schedule->next_due_date->toDateString() <= $by)
                    : ($marker->status === 'New' || !$marker->next_review || $marker->next_review->toDateString() <= $by);
            });
            $overdue = $due->contains(function ($marker) use ($schedules, $today) {
                $date = $schedules->get($marker->id)?->next_due_date ?? $marker->next_review;

                return $date && $date->toDateString() < $today;
            });
            $unlinked = $codes->diff($cases->pluck('code'))->reject(fn ($code) =>
                in_array($code, ['IM18','IM19','IM27','IM28','IM32','IM33'], true));
            $actions = $open->filter(fn ($marker) => filled($marker->next_action))->map(fn ($marker) => [
                'title' => $marker->next_action, 'owner' => $marker->owner?->name, 'due' => $marker->action_due?->toDateString(),
            ])->values()->concat($shared->get($person->id, collect())->map(fn ($action) => [
                'title' => $action->details['summary'] ?? 'Follow-up action', 'owner' => $action->owner?->name, 'due' => $action->due_date->toDateString(),
            ]));
            foreach ($plans->get($person->id, collect())->where('status', 'Active') as $plan) {
                foreach ($plan->activities ?? [] as $activity) {
                    $followup = $activity['followup'] ?? null;
                    if ($followup && empty($followup['completed_at'])) {
                        $actions->push(['title'=>$followup['note'], 'owner'=>$followup['owner_name'], 'due'=>$followup['due']]);
                    }
                }
            }

            return ['person' => $person, 'open' => $open, 'codes' => $codes, 'due' => $due, 'unlinked' => $unlinked,
                'next_action' => $actions->sortBy(fn ($action) => $action['due'] ?? '9999-12-31')->first(),
                'needs_review' => $due->isNotEmpty() || $unlinked->isNotEmpty(), 'overdue' => $overdue];
        })->when(isset($filters['marker']), fn ($rows) => $rows->filter(fn ($row) => $row['codes']->contains($filters['marker'])));

        $dueCount = $rows->where('needs_review', true)->count();
        $allCount = $rows->count();
        $overdueActions = $rows->filter(fn ($row) => filled($row['next_action']['due'] ?? null) && $row['next_action']['due'] < today()->toDateString())->count();
        if (($filters['view'] ?? 'all') === 'needs-review') {
            $rows = $rows->where('needs_review', true)->sortByDesc('overdue');
        }

        return ['meetingRows'=>$rows->values(),'rows' => SupportFollowup::paginate($rows->values(), $request, 'page'), 'allCount' => $allCount,
            'dueCount' => $dueCount, 'by' => $by, 'codes' => $this->codes(), 'view' => $filters['view'] ?? 'all',
            'overdueActions' => $overdueActions];
    }

    public function registerReport(Request $request): Response
    {
        abort_unless($this->team($request), 403);
        $people = Apprentice::whereIn('id', SupportAccess::ids($request->user()))->with('groupRecord')->get();
        $concerns = SupportConcern::with('owner:id,name')->whereIn('apprentice_id', $people->pluck('id'))->get();
        $plans = SupportPlan::whereIn('apprentice_id', $people->pluck('id'))->get(['id','apprentice_id','status','activities','reviews']);
        $data = $this->registerData($request, $people, $concerns, $plans, []);
        $open = $concerns->where('status', '!=', 'Closed');
        $summary = $open->whereNotNull('code')->groupBy('code')->map(fn ($markers) => $markers->pluck('apprentice_id')->unique()->count());
        $delivered = $plans->sum(fn ($plan) => count($plan->activities ?? []));
        $impact = $plans->sum(fn ($plan) => count($plan->reviews ?? []));
        return response()->view('reports.support', $data + ['summary' => $summary, 'delivered' => $delivered, 'impact' => $impact], 200, self::PRIVATE_CACHE);
    }

    public function trackMarker(Request $request, Apprentice $apprentice): RedirectResponse
    {
        abort_unless($this->lead($request, $apprentice), 403);
        $data = $request->validate(['code'=>['required',Rule::in(array_keys($this->codes()))],
            'other'=>['nullable','required_if:code,IM40','string','max:250'],
            'frequency'=>['required','in:weekly,fortnightly,monthly,quarterly,six-monthly']]);
        DB::transaction(function () use ($request, $apprentice, $data): void {
            $profile = Apprentice::whereKey($apprentice->id)->lockForUpdate()->firstOrFail();
            abort_unless($this->lead($request, $profile), 403);
            if (SupportConcern::where('apprentice_id',$profile->id)->where('code',$data['code'])->where('status','!=','Closed')->exists()) {
                throw ValidationException::withMessages(['code'=>'This marker already has an open review record.']);
            }
            $marker = new SupportConcern(['apprentice_id'=>$profile->id,'raised_by'=>$request->user()->id,'initial_note'=>'Marker added to the Support and Retention Register.']);
            $marker->code = $data['code'];
            $marker->other_code = $data['code'] === 'IM40' ? $data['other'] : null;
            $marker->owner_id = $request->user()->id;
            $marker->history = [['event'=>'marker_review_frequency_set','frequency'=>$data['frequency'],
                'by'=>$request->user()->id,'at'=>now()->toIso8601String()]];
            $marker->save();
            $before = MarkerHistory::codes($profile->id);
            DB::table('apprentice_markers')->insertOrIgnore(['apprentice_id'=>$profile->id,'code'=>$marker->code,
                'recorded_by'=>$request->user()->id,'created_at'=>now(),'updated_at'=>now()]);
            MarkerHistory::record($profile->id,$request->user()->id,'register_marker',$before);
            $this->audit($request,$profile->id,$marker->id,'register_marker_added');
        });

        return back()->with('status','Marker added for a first review.');
    }

    public function formalMarkerReview(Request $request, SupportConcern $concern): RedirectResponse
    {
        abort_unless($this->team($request, $concern->apprentice), 403);
        $periods = ['weekly','fortnightly','monthly','quarterly','six-monthly'];
        $data = $request->validate([
            'version' => ['required','integer'],
            'date' => ['required','date_format:Y-m-d','before_or_equal:today'],
            'frequency' => ['required', Rule::in($periods)],
            'outcome' => ['required','string','max:1000'],
            'action' => ['nullable','string','max:1000'],
            'owner' => ['nullable','required_with:action,due','integer'],
            'due' => ['nullable','required_with:action,owner','date_format:Y-m-d','after_or_equal:date'],
        ]);
        DB::transaction(function () use ($request, $concern, $data): void {
            $marker = SupportConcern::whereKey($concern->id)->lockForUpdate()->firstOrFail();
            abort_unless($this->team($request, $marker->apprentice), 403);
            $this->version($request, $marker);
            $history = $marker->history ?? [];
            $previous = collect($history)->last(fn ($event) => ($event['event'] ?? '') === 'formal_marker_review');
            if (SupportMarkerSchedule71::where('support_concern_id', $marker->id)->exists()) {
                throw ValidationException::withMessages(['date' => 'Use the scheduled marker review on the support record.']);
            }
            if ($marker->status === 'Closed' || !$marker->code || $data['date'] < $marker->created_at->toDateString()
                || ($previous && $data['date'] < $previous['date'])) {
                throw ValidationException::withMessages(['date' => 'Use a current open marker and a date within its review history.']);
            }
            $date = \Carbon\CarbonImmutable::parse($data['date']);
            $next = match ($data['frequency']) {
                'weekly' => $date->addWeek(), 'fortnightly' => $date->addWeeks(2), 'monthly' => $date->addMonthNoOverflow(),
                'quarterly' => $date->addMonthsNoOverflow(3), 'six-monthly' => $date->addMonthsNoOverflow(6),
            };
            if (filled($data['action'] ?? null)) {
                if (!SupportAccess::owners($marker->apprentice)->contains('id', (int) $data['owner'])) {
                    throw ValidationException::withMessages(['owner' => 'Choose an eligible owner for this apprentice.']);
                }
                if (filled($marker->next_action)) {
                    throw ValidationException::withMessages(['action' => 'Complete the open action before replacing it. You can still save the review by itself.']);
                }
                $marker->owner_id = $data['owner'];
                $marker->action_due = $data['due'];
                $marker->next_action = $data['action'];
            } elseif (filled($data['owner'] ?? null) || filled($data['due'] ?? null)) {
                throw ValidationException::withMessages(['action' => 'Enter the follow-up action or clear its owner and due date.']);
            }
            $history[] = ['event' => 'formal_marker_review', 'date' => $data['date'], 'frequency' => $data['frequency'],
                'outcome' => $data['outcome'], 'next_review' => $next->toDateString(), 'by' => $request->user()->id, 'at' => now()->toIso8601String()];
            $marker->history = $history;
            $marker->acknowledged_at ??= now();
            if ($marker->status === 'New') {
                $marker->status = 'Open';
            }
            $marker->next_review = $next;
            $marker->version++;
            $marker->save();
            $this->audit($request, $marker->apprentice_id, $marker->id, 'formal_marker_review');
        });

        return back()->with('status', 'Formal marker review saved.');
    }

    public function completeMarkerAction(Request $request, SupportConcern $concern): RedirectResponse
    {
        abort_unless($this->team($request, $concern->apprentice), 403);
        $request->validate(['version'=>['required','integer'],'outcome'=>['required','string','max:1000']]);
        DB::transaction(function () use ($request, $concern): void {
            $marker = SupportConcern::whereKey($concern->id)->lockForUpdate()->firstOrFail();
            abort_unless($this->team($request, $marker->apprentice), 403);
            $this->version($request, $marker);
            if ($marker->status === 'Closed' || !filled($marker->next_action)) {
                throw ValidationException::withMessages(['outcome' => 'Choose a marker with an open action.']);
            }
            $marker->history = [...($marker->history ?? []), ['event'=>'marker_action_completed',
                'before'=>$marker->only('next_action','owner_id','action_due'), 'outcome'=>$request->input('outcome'),
                'by'=>$request->user()->id,'at'=>now()->toIso8601String()]];
            $marker->next_action = null;
            $marker->action_due = null;
            $marker->version++;
            $marker->save();
            $this->audit($request, $marker->apprentice_id, $marker->id, 'marker_action_completed');
        });

        return back()->with('status', 'Action completed.');
    }

    public function index(Request $r, Apprentice $apprentice): Response
    {
        return response()->view('support.index',$this->recordData($r,$apprentice),200,self::PRIVATE_CACHE);
    }

    private function recordData(Request $r, Apprentice $apprentice): array
    {
        abort_unless($this->raiseAllowed($r, $apprentice), 403);
        $team = $this->team($r, $apprentice);
        $concerns = SupportConcern::with('owner')->where('apprentice_id', $apprentice->id)->when(! $team, fn ($q) => $q->where('raised_by', $r->user()->id))->orderByDesc('created_at')->get();

        $schedules = $team ? SupportMarkerSchedule71::where('apprentice_id', $apprentice->id)->get()->keyBy('support_concern_id') : collect();
        $sharedActions = $team ? SupportSharedAction71::with('owner:id,name')->where('apprentice_id', $apprentice->id)->orderBy('due_date')->get() : collect();
        return ['apprentice' => $apprentice, 'schedules' => $schedules, 'sharedActions' => $sharedActions, 'team' => $team, 'lead' => $this->lead($r, $apprentice), 'concerns' => $concerns, 'codes' => $this->codes(), 'markers' => $team ? DB::table('apprentice_markers')->where('apprentice_id', $apprentice->id)->pluck('code')->all() : [], 'markerHistory' => $team ? DB::table('marker_change_events')->where('apprentice_id', $apprentice->id)->orderByDesc('id')->get() : collect(), 'owners' => $team ? SupportAccess::owners($apprentice) : collect(), 'instructionWriter' => SupportAccess::writer($r->user(), $apprentice) || ExecutiveAccess::enabled($r), 'instructionApprover' => SupportAccess::approver($r->user(), $apprentice) || ExecutiveAccess::enabled($r), 'instructions' => $team ? TeachingInstruction::where('apprentice_id', $apprentice->id)->get() : collect(), 'plans' => $team ? SupportPlan::where('apprentice_id', $apprentice->id)->get() : collect(), 'recipients' => $team ? User::whereIn('id', $this->recipients($apprentice))->where('active', true)->get() : collect()];
    }

    public function raise(Request $r, Apprentice $apprentice): RedirectResponse
    {
        abort_unless($this->raiseAllowed($r, $apprentice), 403);
        $data = $r->validate(['initial_note' => ['required', 'string', 'max:10000'], 'code' => ['prohibited'], 'owner_id' => ['prohibited']]);
        DB::transaction(function () use ($r, $apprentice, $data): void {
            $fresh = Apprentice::whereKey($apprentice->id)->lockForUpdate()->firstOrFail();
            abort_unless($this->raiseAllowed($r, $fresh), 403);
            $c = SupportConcern::create([...$data, 'apprentice_id' => $apprentice->id, 'raised_by' => $r->user()->id]);
            $this->audit($r, $apprentice->id, $c->id, 'concern_raised');
        });

        return back()->with('status', 'Concern raised for acknowledgement. No marker was allocated automatically.');
    }

    public function classify(Request $r, SupportConcern $concern): RedirectResponse
    {
        abort_unless($this->lead($r, $concern->apprentice), 403);
        $data = $r->validate(['version' => ['required', 'integer'], 'code' => ['required', Rule::in(array_keys($this->codes()))], 'other_code' => ['nullable', 'required_if:code,IM40', 'string', 'max:250'], 'owner_id' => ['required', 'integer', Rule::in(SupportAccess::owners($concern->apprentice)->pluck('id')->all())], 'next_action' => ['required', 'string', 'max:5000'], 'action_due' => ['required', 'date_format:Y-m-d'], 'next_review' => ['required', 'date_format:Y-m-d']]);
        DB::transaction(function () use ($r, $concern, $data): void {
            $profile = Apprentice::whereKey($concern->apprentice_id)->lockForUpdate()->firstOrFail();
            $c = SupportConcern::whereKey($concern->id)->lockForUpdate()->firstOrFail();
            $this->version($r, $c);
            if ($c->status === 'Closed') {
                throw ValidationException::withMessages(['code' => 'Closed concerns retain their history. Raise a new concern for a new episode.']);
            }$h = $c->history ?? [];
            $h[] = ['event' => 'classified', 'before' => $c->only('code', 'owner_id', 'next_action', 'action_due', 'next_review'), 'by' => $r->user()->id, 'at' => now()->toIso8601String()];
            $c->history = $h;
            $c->code = $data['code'];
            $c->other_code = $data['code'] === 'IM40' ? $data['other_code'] : null;
            abort_unless(SupportAccess::team(User::findOrFail($data['owner_id']), $profile), 403);
            $c->owner_id = $data['owner_id'];
            $c->next_action = $data['next_action'];
            $c->action_due = $data['action_due'];
            $c->next_review = $data['next_review'];
            $c->acknowledged_at ??= now();
            $c->acknowledged_by ??= $r->user()->id;
            $c->status = 'Monitoring';
            $c->version++;
            $c->save();
            $beforeMarkers = MarkerHistory::codes($profile->id);
            DB::table('apprentice_markers')->insertOrIgnore(['apprentice_id' => $c->apprentice_id, 'code' => $c->code, 'recorded_by' => $r->user()->id, 'created_at' => now(), 'updated_at' => now()]);
            MarkerHistory::record($profile->id, $r->user()->id, 'concern_classification', $beforeMarkers, 'Concern '.$c->id);
            $profile->version++;
            $profile->save();
            $this->audit($r, $c->apprentice_id, $c->id, 'acknowledged_classified');
        });

        return back()->with('status', 'Concern acknowledged, coded and assigned.');
    }

    public function followup(Request $r, SupportConcern $concern): RedirectResponse
    {
        abort_unless($this->team($r, $concern->apprentice), 403);
        $data = $r->validate(['version' => ['required', 'integer'], 'date' => ['required', 'date_format:Y-m-d', 'before_or_equal:today'], 'note' => ['required', 'string', 'max:10000'], 'target' => ['required', 'string', 'max:5000'], 'measure' => ['required', 'string', 'max:5000'], 'owner' => ['required', 'string', 'max:150'], 'due_date' => ['required', 'date_format:Y-m-d', 'after_or_equal:date'], 'next_review' => ['required', 'date_format:Y-m-d', 'after_or_equal:date'], 'outcome' => ['required', Rule::in(['Progress made', 'More support needed', 'No progress yet', 'Target completed'])], 'agreed' => ['accepted']]);
        DB::transaction(function () use ($r, $concern, $data): void {
            $c = SupportConcern::whereKey($concern->id)->lockForUpdate()->firstOrFail();
            $this->version($r, $c);
            if (! $c->acknowledged_at || $c->status === 'Closed') {
                throw ValidationException::withMessages(['note' => 'Acknowledge the concern before recording weekly follow-up.']);
            }$h = $c->followups ?? [];
            if ($data['date'] < $c->created_at->toDateString() || ($h && $data['date'] < end($h)['date'])) {
                throw ValidationException::withMessages(['date' => 'Use a follow-up date within this concern, not before its previous follow-up.']);
            }$h[] = [...collect($data)->except('version')->all(), 'by' => $r->user()->id, 'recorded_at' => now()->toIso8601String()];
            $c->followups = $h;
            $c->next_action = $data['target'];
            $c->action_due = $data['due_date'];
            if (!collect($c->history ?? [])->contains('event', 'formal_marker_review')) {
                $c->next_review = $data['next_review'];
            }
            $c->version++;
            $c->save();
            $this->audit($r, $c->apprentice_id, $c->id, 'weekly_followup');
        });

        return back()->with('status', 'Weekly follow-up saved chronologically.');
    }

    public function close(Request $r, SupportConcern $concern): RedirectResponse
    {
        abort_unless($this->lead($r, $concern->apprentice), 403);
        $r->validate(['version' => ['required', 'integer'], 'outcome' => ['required', 'string', 'max:10000']]);
        DB::transaction(function () use ($r, $concern): void {
            $c = SupportConcern::whereKey($concern->id)->lockForUpdate()->firstOrFail();
            $this->version($r, $c);
            if ($c->status === 'Closed') {
                throw ValidationException::withMessages(['outcome' => 'This concern is already closed.']);
            }$c->status = 'Closed';
            $c->outcome = $r->input('outcome');
            $c->closed_at = now();
            $c->version++;
            $c->save();
            $this->audit($r, $c->apprentice_id, $c->id, 'closed');
        });

        return back()->with('status', 'Concern closed; history and markers retained.');
    }

    public function markers(Request $r, Apprentice $apprentice): RedirectResponse
    {
        abort_unless($this->lead($r, $apprentice), 403);
        $data = $r->validate(['version' => ['required', 'integer'], 'marker_reason' => ['nullable', 'string', 'max:2000'], 'codes' => ['nullable', 'array', 'max:40'], 'codes.*' => ['required', Rule::in(array_keys($this->codes()))], 'confirmed' => ['accepted']]);
        DB::transaction(function () use ($r, $apprentice, $data): void {
            $locked = Apprentice::whereKey($apprentice->id)->lockForUpdate()->firstOrFail();
            $this->version($r, $locked);
            $locked->inclusion_status68 = count($data['codes'] ?? []) > 0 ? 'selected' : 'none';
            $locked->version++;
            $locked->save();
            $before = MarkerHistory::codes($apprentice->id);
            $selected = array_values(array_unique($data['codes'] ?? []));
            DB::table('apprentice_markers')->where('apprentice_id', $apprentice->id)->whereNotIn('code', $selected)->delete();
            foreach (array_diff($selected, $before) as $code) {
                DB::table('apprentice_markers')->insert(['apprentice_id' => $apprentice->id, 'code' => $code, 'recorded_by' => $r->user()->id, 'created_at' => now(), 'updated_at' => now()]);
            }
            MarkerHistory::record($apprentice->id, $r->user()->id, 'manual_selection', $before, $data['marker_reason'] ?? null);
            $this->audit($r, $apprentice->id, 0, 'markers_updated');
        });

        return back()->with('status', 'Inclusion markers saved. A support-plan requirement is separate from an approved active plan.');
    }

    public function instruction(Request $r, Apprentice $apprentice): RedirectResponse
    {
        abort_unless(SupportAccess::writer($r->user(), $apprentice) || ExecutiveAccess::enabled($r), 403);
        $data = $r->validate(['draft_text' => ['required', 'string', 'max:10000'], 'private_note' => ['nullable', 'string', 'max:10000'], 'recipient_ids' => ['required', 'array', 'min:1'], 'recipient_ids.*' => ['required', 'integer', Rule::in($this->recipients($apprentice))]]);
        DB::transaction(function () use ($r, $apprentice, $data): void {
            $fresh = Apprentice::whereKey($apprentice->id)->lockForUpdate()->firstOrFail();
            abort_unless(SupportAccess::writer($r->user(), $fresh) || ExecutiveAccess::enabled($r), 403);
            $i = TeachingInstruction::create([...$data, 'recipient_ids' => array_map('intval', array_unique($data['recipient_ids'])), 'apprentice_id' => $apprentice->id]);
            $this->audit($r, $apprentice->id, $i->id, 'instruction_drafted');
        });

        return back()->with('status', 'Instruction draft saved. Tutors cannot see it until approved.');
    }

    public function amendInstruction(Request $r, TeachingInstruction $instruction): RedirectResponse
    {
        abort_unless(SupportAccess::writer($r->user(), $instruction->apprentice) || ExecutiveAccess::enabled($r), 403);
        $data = $r->validate(['version' => ['required', 'integer'], 'draft_text' => ['required', 'string', 'max:10000'], 'private_note' => ['nullable', 'string', 'max:10000'], 'recipient_ids' => ['required', 'array', 'min:1'], 'recipient_ids.*' => ['integer', Rule::in($this->recipients($instruction->apprentice))]]);
        DB::transaction(function () use ($r, $instruction, $data): void {
            $i = TeachingInstruction::whereKey($instruction->id)->lockForUpdate()->firstOrFail();
            $this->version($r, $i);
            $h = $i->history ?? [];
            $h[] = ['event' => 'Draft amended', 'draft_revision' => $i->draft_revision, 'before' => $i->only('draft_text', 'private_note', 'recipient_ids'), 'by' => $r->user()->id, 'at' => now()->toIso8601String()];
            $i->fill(collect($data)->except('version')->all());
            $i->recipient_ids = array_map('intval', array_unique($data['recipient_ids']));
            $i->draft_revision++;
            $i->version++;
            $i->history = $h;
            $i->save();
            $this->audit($r, $i->apprentice_id, $i->id, 'instruction_draft_amended');
        });

        return back()->with('status', 'Instruction draft amended. Any currently approved copy remains unchanged until a new version is approved.');
    }

    public function withdrawInstruction(Request $r, TeachingInstruction $instruction): RedirectResponse
    {
        abort_unless(SupportAccess::approver($r->user(), $instruction->apprentice) || ExecutiveAccess::enabled($r), 403);
        $data = $r->validate(['version' => ['required', 'integer'], 'release_version' => ['required', 'integer'], 'reason' => ['required', 'string', 'max:5000']]);
        DB::transaction(function () use ($r, $instruction, $data): void {
            $i = TeachingInstruction::whereKey($instruction->id)->lockForUpdate()->firstOrFail();
            $this->version($r, $i);
            $release = $i->currentRelease();
            if (! $release || $release['version'] !== $r->integer('release_version')) {
                throw ValidationException::withMessages(['release_version' => 'The approved instruction changed or was already withdrawn. Reopen it.']);
            }
            $release['withdrawn_at'] = now()->toIso8601String();
            $release['withdrawn_by'] = $r->user()->id;
            $release['withdrawal_reason'] = $data['reason'];
            $i->release = $release;
            $h = $i->history ?? [];
            $h[] = ['event' => 'Release withdrawn', 'release' => $release, 'by' => $r->user()->id, 'at' => now()->toIso8601String()];
            $i->history = $h;
            $i->version++;
            $i->save();
            $this->audit($r, $i->apprentice_id, $i->id, 'instruction_withdrawn');
        });

        return back()->with('status', 'Approved instruction withdrawn. It is no longer shown to tutors; its history remains restricted.');
    }

    public function release(Request $r, TeachingInstruction $instruction): RedirectResponse
    {
        abort_unless(SupportAccess::approver($r->user(), $instruction->apprentice) || ExecutiveAccess::enabled($r), 403);
        $r->validate(['version' => ['required', 'integer'], 'confirmed' => ['accepted']]);
        DB::transaction(function () use ($r, $instruction): void {
            $i = TeachingInstruction::whereKey($instruction->id)->lockForUpdate()->firstOrFail();
            $this->version($r, $i);
            if (! $i->hasUnreleasedDraft()) {
                throw ValidationException::withMessages(['confirmed' => 'This instruction is already approved.']);
            }$allowed = $this->recipients($i->apprentice);
            if (array_diff($i->recipient_ids, $allowed)) {
                throw ValidationException::withMessages(['confirmed' => 'A recipient assignment changed. Draft a new instruction for the current recipients.']);
            }if ($i->release) {
                $history = $i->history ?? [];
                $history[] = ['event' => 'Release replaced', 'release' => $i->release, 'acknowledgements' => $i->acknowledgements, 'by' => $r->user()->id, 'at' => now()->toIso8601String()];
                $i->history = $history;
            }
            $i->acknowledgements = [];
            $i->release = ['draft_revision' => $i->draft_revision, 'version' => $i->version, 'text' => $i->draft_text, 'recipient_ids' => $i->recipient_ids, 'approved_by' => $r->user()->id, 'approved_at' => now()->toIso8601String()];
            $i->version++;
            $i->save();
            $this->audit($r, $i->apprentice_id, $i->id, 'instruction_approved');
        });

        return back()->with('status', 'Teaching instruction approved for its named linked tutors. Private notes remain restricted.');
    }

    public function instructions(Request $r, Apprentice $apprentice): View
    {
        abort_unless(Access::tutor($r->user(), $apprentice) || Access::subject($r->user(), $apprentice, 'maths') || Access::subject($r->user(), $apprentice, 'english'), 403);
        $instructions = TeachingInstruction::where('apprentice_id', $apprentice->id)->get()->filter(fn ($i) => $i->currentRelease() && in_array($r->user()->id, $i->release['recipient_ids'], true));

        return view('support.instructions', ['apprentice' => $apprentice, 'instructions' => $instructions]);
    }

    public function acknowledgeInstruction(Request $r, TeachingInstruction $instruction): RedirectResponse
    {
        $a = $instruction->apprentice;
        abort_unless((Access::tutor($r->user(), $a) || Access::subject($r->user(), $a, 'maths') || Access::subject($r->user(), $a, 'english')) && $instruction->currentRelease() && in_array($r->user()->id, $instruction->release['recipient_ids'], true), 403);
        $r->validate(['version' => ['required', 'integer'], 'confirmed' => ['accepted']]);
        DB::transaction(function () use ($r, $instruction): void {
            $i = TeachingInstruction::whereKey($instruction->id)->lockForUpdate()->firstOrFail();
            $this->version($r, $i);
            abort_unless($i->currentRelease() && in_array($r->user()->id, $i->release['recipient_ids'], true), 403);
            $acks = $i->acknowledgements ?? [];
            $acks[(string) $r->user()->id] = now()->toIso8601String();
            $i->acknowledgements = $acks;
            $i->version++;
            $i->save();
            $this->audit($r, $i->apprentice_id, $i->id, 'instruction_read');
        });

        return back()->with('status', 'Instruction acknowledged.');
    }

    public function planPdf(Request $r, SupportPlan $plan): Response
    {
        abort_unless($this->team($r, $plan->apprentice), 403);
        abort_unless($plan->active_plan, 409, 'No approved support-plan snapshot exists.');

        return PortalPdf::response('pdf.support', ['plan' => $plan, 'codes' => $this->codes()], 'GTA-Private-ASP-'.$plan->apprentice->reference.'-'.$plan->id);
    }

    public function plan(Request $r, Apprentice $apprentice, ?SupportPlan $plan = null): View
    {
        abort_unless($this->team($r, $apprentice), 403);
        if ($plan) {
            abort_unless($plan->apprentice_id === $apprentice->id, 404);
        }

        return view('support.plan', ['apprentice' => $apprentice, 'plan' => $plan ?? new SupportPlan, 'concerns' => SupportConcern::where('apprentice_id', $apprentice->id)->whereNotNull('code')->whereNotIn('code', ['IM18', 'IM19', 'IM27', 'IM28', 'IM32', 'IM33'])->get(), 'lead' => $this->lead($r, $apprentice), 'activityStaff' => User::where('active', true)->whereNotIn('role', ['apprentice', 'employer'])->get(['id', 'name']), 'codes' => $this->codes()]);
    }

    public function savePlan(Request $r, Apprentice $apprentice, ?SupportPlan $plan = null): RedirectResponse
    {
        abort_unless($this->team($r, $apprentice), 403);
        if (\App\Support\RoutineSupport::is($plan)) {
            throw ValidationException::withMessages(['fields' => 'Use the routine-support form for this plan. Its non-funded basis is retained.']);
        }
        if ($plan) {
            abort_unless($plan->apprentice_id === $apprentice->id, 404);
        }
        $data = $r->validate(['version' => $plan ? ['required', 'integer'] : ['prohibited'], 'concern_ids' => ['required', 'array', 'min:1'], 'concern_ids.*' => ['required', 'integer', Rule::exists('support_concerns', 'id')->where('apprentice_id', $apprentice->id)->whereNotNull('code')->whereNotIn('code', ['IM18', 'IM19', 'IM27', 'IM28', 'IM32', 'IM33'])], 'fields' => ['required', 'array'], 'fields.type' => ['nullable', Rule::in(['full', 'light_touch'])], 'fields.frequency' => ['nullable', Rule::in(['Every 4 weeks', 'Every 6 weeks', 'Every 8 weeks', 'Quarterly'])], 'fields.funding' => ['nullable', Rule::in(['not_funded', 'awaiting_assessment', 'als_funded'])], 'fields.referral' => ['nullable', 'date_format:Y-m-d', 'before_or_equal:today'], 'fields.review' => ['nullable', 'date_format:Y-m-d'], 'fields.referred' => ['nullable', 'string', 'max:500'], 'fields.source' => ['nullable', 'string', 'max:1000'], 'fields.barriers' => ['nullable', 'string', 'max:10000'], 'fields.strengths' => ['nullable', 'string', 'max:10000'], 'fields.view' => ['nullable', 'string', 'max:10000'], 'fields.support' => ['nullable', 'string', 'max:10000'], 'fields.rationale' => ['nullable', 'string', 'max:10000']]);
        $saved = DB::transaction(function () use ($r, $apprentice, $plan, $data) {
            $fresh = Apprentice::whereKey($apprentice->id)->lockForUpdate()->firstOrFail();
            abort_unless($this->team($r, $fresh), 403);
            if (! $plan && SupportPlan::where('apprentice_id', $apprentice->id)->where('status', '!=', 'Closed')->exists()) {
                throw ValidationException::withMessages(['fields' => 'A current plan already exists. Open that plan to update it.']);
            }
            $p = $plan ? SupportPlan::whereKey($plan->id)->lockForUpdate()->firstOrFail() : new SupportPlan(['apprentice_id' => $apprentice->id]);
            if ($p->exists) {
                $this->version($r, $p);
                if ($p->status === 'Closed') {
                    throw ValidationException::withMessages(['fields' => 'Closed support history cannot be edited. Start a new plan.']);
                }
            }$ids = array_map('intval', array_unique($data['concern_ids']));
            $codes = SupportConcern::whereIn('id', $ids)->pluck('code', 'id')->all();
            $history = $p->history ?? [];
            $history[] = ['event' => 'draft_saved', 'fields' => $data['fields'], 'codes' => $codes, 'concern_ids' => $ids, 'by' => $r->user()->id, 'at' => now()->toIso8601String()];
            $allowedFields = ['type', 'frequency', 'funding', 'referral', 'review', 'referred', 'source', 'barriers', 'strengths', 'view', 'support', 'rationale'];
            $p->draft = ['fields' => collect($data['fields'])->only($allowedFields)->all(), 'codes' => $codes];
            $p->concern_ids = $ids;
            $p->history = $history;
            if ($p->exists) {
                $p->version++;
            }$p->save();
            $this->audit($r, $apprentice->id, $p->id, 'plan_draft_saved');

            return $p;
        });

        return to_route('support.plan', [$apprentice, $saved])->with('status', 'Support-plan draft saved privately. An existing active version remains unchanged until approval.');
    }

    public function approvePlan(Request $r, SupportPlan $plan): RedirectResponse
    {
        abort_unless($this->lead($r, $plan->apprentice), 403);
        if (\App\Support\RoutineSupport::is($plan)) {
            throw ValidationException::withMessages(['fields' => 'Use the routine-support form for this plan. Its non-funded basis is retained.']);
        }
        $r->validate(['version' => ['required', 'integer'], 'confirmed' => ['accepted'], 'reason' => ['required', 'string', 'max:2000']]);
        DB::transaction(function () use ($r, $plan): void {
            $p = SupportPlan::whereKey($plan->id)->lockForUpdate()->firstOrFail();
            $this->version($r, $p);
            if ($p->status === 'Closed' || ($p->active_plan['draft_hash'] ?? null) === hash('sha256', json_encode($p->draft))) {
                throw ValidationException::withMessages(['confirmed' => 'This draft is closed or already active.']);
            }$f = $p->draft['fields'];
            if (($f['funding'] ?? null) === 'als_funded' && ! filled($f['rationale'] ?? null)) {
                throw ValidationException::withMessages(['fields' => 'Record the ALS claim rationale before approval.']);
            }
            foreach (['type', 'referral', 'barriers', 'strengths', 'view', 'support', 'review'] as $key) {
                if (! filled($f[$key] ?? null)) {
                    throw ValidationException::withMessages(['fields' => 'Complete the draft before approval: '.$key]);
                }
            }if ($f['review'] < today()->toDateString() || $f['referral'] > today()->toDateString()) {
                throw ValidationException::withMessages(['fields' => 'Check the referral date and set the next review to today or later.']);
            }$latest = SupportConcern::where('apprentice_id', $p->apprentice_id)->whereIn('id', $p->concern_ids)->lockForUpdate()->pluck('code', 'id')->all();
            if (count($latest) !== count($p->concern_ids) || array_diff(array_values($latest), array_keys($this->codes())) || array_intersect(array_values($latest), ['IM18', 'IM19', 'IM27', 'IM28', 'IM32', 'IM33']) || $latest !== $p->draft['codes']) {
                throw ValidationException::withMessages(['fields' => 'A linked concern code changed. Save a checked draft before approval.']);
            }$active = ['fields' => $f, 'concern_ids' => $p->concern_ids, 'codes' => $latest, 'draft_hash' => hash('sha256', json_encode($p->draft)), 'approved_at' => now()->toIso8601String(), 'approved_by' => $r->user()->id, 'reason' => $r->input('reason')];
            $h = $p->history ?? [];
            $h[] = ['event' => 'approved', 'release' => $active, 'at' => now()->toIso8601String()];
            $p->history = $h;
            $p->active_plan = $active;
            $p->status = 'Active';
            $p->version++;
            $p->save();
            $this->audit($r, $p->apprentice_id, $p->id, 'plan_approved');
        });

        return back()->with('status', 'Exact support-plan version approved. No employer sharing, funding claim or OTJ hours were generated.');
    }

    public const ACTIVITY_TYPES = ['Specialist 1:1 Coaching', 'In-Class Teaching Adjustment', 'Assistive Tech Setup & Support', 'Pastoral & Wellbeing Check-in', 'Workplace Mentor Consultation', 'Assessment & Exam Preparation'];

    public function supportObjective(Request $r, SupportPlan $plan): RedirectResponse
    {
        abort_unless($this->team($r, $plan->apprentice), 403);
        $data = $r->validate(['version'=>['required','integer'],'objective'=>['required','string','max:250']]);
        DB::transaction(function () use ($r, $plan, $data): void {
            $p = SupportPlan::whereKey($plan->id)->lockForUpdate()->firstOrFail();
            abort_unless($this->team($r, $p->apprentice), 403);
            $this->version($r, $p);
            if ($p->status !== 'Active' || !$p->active_plan) {
                throw ValidationException::withMessages(['objective'=>'Use an active support plan.']);
            }
            $existing = collect($p->activeObjectives())->contains(fn ($item) => strcasecmp($item['title'], $data['objective']) === 0);
            if ($existing) {
                throw ValidationException::withMessages(['objective'=>'This objective is already recorded on the plan.']);
            }
            $p->history = [...($p->history ?? []), [
                'event'=>'support_objective_added', 'approval'=>$p->objectiveApproval(),
                'objective'=>['id'=>(string) Str::uuid(), 'title'=>$data['objective']],
                'by'=>$r->user()->id, 'at'=>now()->toIso8601String(),
            ]];
            $p->version++;
            $p->save();
            $this->audit($r, $p->apprentice_id, $p->id, 'support_objective_added');
        });
        return back()->with('status', 'Support objective saved.');
    }

    public function completeActivityFollowup(Request $r, SupportPlan $plan, string $activity): RedirectResponse
    {
        abort_unless($this->team($r, $plan->apprentice), 403);
        $data = $r->validate(['version'=>['required','integer'],'outcome'=>['required','string','max:1000']]);
        DB::transaction(function () use ($r, $plan, $activity, $data): void {
            $p = SupportPlan::whereKey($plan->id)->lockForUpdate()->firstOrFail();
            abort_unless($this->team($r, $p->apprentice), 403);
            $this->version($r, $p);
            $items = $p->activities ?? [];
            $index = collect($items)->search(fn ($item) => $item['id'] === $activity);
            $followup = $index === false ? null : ($items[$index]['followup'] ?? null);
            if ($p->status !== 'Active' || !$followup || isset($followup['completed_at'])) {
                throw ValidationException::withMessages(['outcome'=>'This follow-up is unavailable or already complete.']);
            }
            $items[$index]['followup'] = [...$followup, 'completed_at'=>now()->toIso8601String(),
                'completed_by'=>$r->user()->id, 'outcome'=>$data['outcome']];
            $p->activities = $items;
            $p->version++;
            $p->save();
            $this->audit($r, $p->apprentice_id, $p->id, 'support_activity_followup_completed');
        });
        return back()->with('status', 'Support activity follow-up completed.');
    }

    public function supportActivity(Request $r, SupportPlan $plan): RedirectResponse
    {
        abort_unless($this->team($r, $plan->apprentice), 403);
        $data = $r->validate(['version' => ['required', 'integer'], 'approved_at' => ['required', 'string'], 'request_id' => ['required', 'uuid'], 'date' => ['required', 'date_format:Y-m-d', 'before_or_equal:today'], 'type' => ['required', Rule::in(self::ACTIVITY_TYPES)], 'minutes' => ['nullable', 'integer', 'min:1', 'max:1440'], 'who' => ['required', 'string'], 'other_name' => ['nullable', 'required_if:who,other', 'string', 'max:250'], 'delivered' => ['required', 'string', 'max:10000'], 'response' => ['nullable', 'string', 'max:10000'], 'impact' => ['nullable', 'string', 'max:10000'],
            'objective_id'=>['nullable','uuid'], 'followup_note'=>['nullable','required_with:followup_due,followup_owner','string','max:1000'],
            'followup_due'=>['nullable','required_with:followup_note','date_format:Y-m-d','after_or_equal:date'],
            'followup_owner'=>['nullable','required_with:followup_note','integer']]);
        DB::transaction(function () use ($r, $plan, $data): void {
            $p = SupportPlan::whereKey($plan->id)->lockForUpdate()->firstOrFail();
            abort_unless($this->team($r, $p->apprentice), 403);
            $this->version($r, $p);
            if ($p->status !== 'Active' || ! $p->active_plan || $p->active_plan['approved_at'] !== $data['approved_at']) {
                throw ValidationException::withMessages(['approved_at' => 'Open the current active support plan before recording delivered support.']);
            }
            if ($data['date'] < substr($p->active_plan['approved_at'], 0, 10)) {
                throw ValidationException::withMessages(['date' => 'Use an actual support date within the current active plan.']);
            }
            if (collect($p->activities ?? [])->contains('request_id', $data['request_id'])) {
                throw ValidationException::withMessages(['request_id' => 'This activity form was already saved. Open a new form for another activity.']);
            }
            $person = $data['who'] === 'other' ? null : User::whereKey($data['who'])->where('active', true)->whereNotIn('role', ['apprentice', 'employer'])->first();
            if (! $person && $data['who'] !== 'other') {
                throw ValidationException::withMessages(['who' => 'Choose an active named staff member or Other named person.']);
            }
            $objective = $p->activeObjectives()[$data['objective_id'] ?? ''] ?? null;
            if (filled($data['objective_id'] ?? null) && !$objective) {
                throw ValidationException::withMessages(['objective_id'=>'Choose an objective from the current active support plan.']);
            }
            $followup = null;
            if (filled($data['followup_note'] ?? null)) {
                $owner = SupportAccess::owners($p->apprentice)->firstWhere('id', (int) $data['followup_owner']);
                if (!$owner) {
                    throw ValidationException::withMessages(['followup_owner'=>'Choose an authorised support team member.']);
                }
                $followup = ['note'=>$data['followup_note'],'due'=>$data['followup_due'],
                    'owner_id'=>$owner->id,'owner_name'=>$owner->name];
            }
            $activity = [...collect($data)->except('version', 'who', 'other_name','objective_id','followup_note','followup_due','followup_owner')->all(), 'id' => (string) Str::uuid(), 'delivered_by' => ['id' => $person?->id, 'name' => $person?->name ?? $data['other_name'], 'reported' => $person === null], 'recorded_at' => now()->toIso8601String(), 'recorded_by' => $r->user()->id];
            $items = $p->activities ?? [];
            $items[] = [...$activity, 'objective'=>$objective, 'followup'=>$followup];
            $p->activities = $items;
            $p->version++;
            $p->save();
            $this->audit($r, $p->apprentice_id, $p->id, 'delivered_support_recorded');
        });

        return back()->with('status', 'Support activity saved.');
    }

    public function uploadPlanEvidence(Request $r, SupportPlan $plan): RedirectResponse
    {
        abort_unless($this->team($r, $plan->apprentice), 403);
        $data = $r->validate(['version' => ['required', 'integer'], 'file' => PrivateEvidence::rules(), 'purpose' => ['required', 'string', 'max:2000'], 'activity_id' => ['nullable', 'uuid']]);
        $entry = null;
        try {
            DB::transaction(function () use ($r, $plan, $data, &$entry): void {
                $p = SupportPlan::whereKey($plan->id)->lockForUpdate()->firstOrFail();
                $this->version($r, $p);
                if (! in_array($p->status, ['Draft', 'Active'], true)) {
                    throw ValidationException::withMessages(['file' => 'Closed support records cannot receive new evidence.']);
                }
                if (filled($data['activity_id'] ?? null) && ! collect($p->activities ?? [])->contains('id', $data['activity_id'])) {
                    throw ValidationException::withMessages(['activity_id' => 'Choose an existing activity from this support plan.']);
                }
                $entry = PrivateEvidence::store($r->file('file'), 'support-plan-evidence', $r->user()->id);
                $files = $p->files ?? [];
                $files[] = [...$entry, 'purpose' => $data['purpose'], 'activity_id' => $data['activity_id'] ?? null];
                $p->files = $files;
                $p->version++;
                $p->save();
                $this->audit($r, $p->apprentice_id, $p->id, 'support_evidence_uploaded');
            });
        } catch (\Throwable $error) {
            if ($entry) {
                PrivateEvidence::delete($entry);
            } throw $error;
        }

        return back()->with('status', 'Evidence saved in the restricted support record. No tutor or employer disclosure was made.');
    }

    public function downloadPlanEvidence(Request $r, SupportPlan $plan, string $file): StreamedResponse
    {
        abort_unless($this->team($r, $plan->apprentice), 403);
        $entry = collect($plan->files ?? [])->firstWhere('id', $file);
        abort_unless($entry && PrivateEvidence::exists($entry), 404);

        return PrivateEvidence::download($entry);
    }

    public function reviewPlan(Request $r, SupportPlan $plan): RedirectResponse
    {
        abort_unless($this->team($r, $plan->apprentice), 403);
        $data = $r->validate(['version' => ['required', 'integer'], 'date' => ['required', 'date_format:Y-m-d', 'before_or_equal:today'], 'baseline' => ['required', 'string', 'max:10000'], 'delivered' => ['required', 'string', 'max:10000'], 'impact' => ['required', 'string', 'max:10000'], 'evidence' => ['required', 'string', 'max:10000'], 'direction' => ['required', Rule::in(['continue', 'adjust_support', 'escalate', 'step_down_close'])], 'next' => ['required', 'date_format:Y-m-d', 'after_or_equal:date'], 'notes' => ['nullable', 'string', 'max:10000']]);
        DB::transaction(function () use ($r, $plan, $data): void {
            $p = SupportPlan::whereKey($plan->id)->lockForUpdate()->firstOrFail();
            $this->version($r, $p);
            if ($p->status !== 'Active' || ! $p->active_plan) {
                throw ValidationException::withMessages(['date' => 'Formal support reviews require an active plan.']);
            }$h = $p->reviews ?? [];
            $last = collect($h)->last();
            if ($data['date'] < substr($p->active_plan['approved_at'], 0, 10) || ($last && $data['date'] < $last['date'])) {
                throw ValidationException::withMessages(['date' => 'A review cannot precede the active plan or previous review.']);
            }$h[] = [...collect($data)->except('version')->all(), 'active_snapshot' => $p->active_plan, 'previous_due' => $p->nextReview(), 'by' => $r->user()->id, 'recorded_at' => now()->toIso8601String()];
            $p->reviews = $h;
            $p->version++;
            $p->save();
            $this->audit($r, $p->apprentice_id, $p->id, 'support_impact_review');
        });

        return back()->with('status', 'Support-impact review saved. Draft changes and funding decisions remain separate.');
    }

    public function closePlan(Request $r, SupportPlan $plan): RedirectResponse
    {
        abort_unless($this->lead($r, $plan->apprentice), 403);
        $r->validate(['version' => ['required', 'integer'], 'outcome' => ['required', 'string', 'max:10000'], 'confirmed' => ['accepted']]);
        DB::transaction(function () use ($r, $plan): void {
            $p = SupportPlan::whereKey($plan->id)->lockForUpdate()->firstOrFail();
            $this->version($r, $p);
            if ($p->status !== 'Active') {
                throw ValidationException::withMessages(['outcome' => 'Only an active plan can be closed.']);
            }$p->status = 'Closed';
            $p->closure_outcome = $r->input('outcome');
            $p->closed_at = now();
            $p->version++;
            $p->save();
            $this->audit($r, $p->apprentice_id, $p->id, 'plan_closed');
        });

        return back()->with('status', 'Support plan closed. History is retained; future support uses a new plan.');
    }

    private function version(Request $r, Model $row): void
    {
        $a = $row instanceof Apprentice ? $row : $row->apprentice;
        $route = $r->route()->getName();
        if ($row instanceof SupportConcern) {
            abort_unless(in_array($route, ['support.classify', 'support.close'], true) ? $this->lead($r, $a) : $this->team($r, $a), 403);
        } elseif ($row instanceof SupportPlan) {
            abort_unless(in_array($route, ['support.plan.approve', 'support.plan.close'], true) ? $this->lead($r, $a) : $this->team($r, $a), 403);
        } elseif ($row instanceof Apprentice) {
            abort_unless($this->lead($r, $a), 403);
        } elseif ($row instanceof TeachingInstruction && $route !== 'support.instruction.ack') {
            abort_unless((in_array($route, ['support.release', 'support.instruction.withdraw'], true) ? SupportAccess::approver($r->user(), $a) : SupportAccess::writer($r->user(), $a)) || ExecutiveAccess::enabled($r), 403);
        }

        if ($row->version !== $r->integer('version')) {
            throw ValidationException::withMessages(['version' => 'This record changed. Reopen before saving.']);
        }
    }

    private function audit(Request $r, int $a, int $id, string $event): void
    {
        if (ExecutiveAccess::enabled($r)) {
            ExecutiveAccess::record($r, $event, 'apprentice:'.$a.'/support:'.$id);
        }
        DB::table('learning_events')->insert(['user_id' => $r->user()->id, 'apprentice_id' => $a, 'kind' => 'support', 'record_id' => $id, 'event' => $event, 'created_at' => now()]);
    }
}

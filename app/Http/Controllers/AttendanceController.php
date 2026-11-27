<?php

namespace App\Http\Controllers;

use App\Models\Apprentice;
use App\Models\AttendanceMark;
use App\Models\ExtraTraining;
use App\Models\MonthlyScorecard;
use App\Models\RegisterTrainingRecord;
use App\Models\TrainingSession;
use App\Models\TutorGroup;
use App\Models\User;
use App\Support\ApprenticeReviewData;
use App\Support\AttendanceLedger;
use App\Support\AttendanceMath as Math;
use App\Support\LearningAccess as Access;
use App\Support\MonthlyDevelopment;
use App\Support\RegisterCreation;
use App\Support\RegisterFollowup;
use App\Support\RegisterPayload;
use App\Support\RegisterTraining;
use App\Support\SessionPlanning;
use App\Support\Workday83;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Pagination\Paginator;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class AttendanceController extends Controller
{
    private function groupAllowed(Request $r, TutorGroup $group): bool
    {
        return Access::manager($r->user()) || ($r->user()->role === 'tutor' && $group->tutor_id === $r->user()->id);
    }

    private function sessionAllowed(Request $r, TrainingSession $s): bool
    {
        return RegisterFollowup::canOpen($r->user(), $s);
    }

    public function index(Request $r): View
    {
        $visible = RegisterFollowup::visible($r->user(), includeInactive: true);
        $groups = $visible['groups'];
        $sessions = $visible['sessions'];
        $input = $r->validate(['view' => ['nullable', 'string', Rule::in(RegisterFollowup::VIEWS)], 'group' => ['nullable', 'integer', 'min:1'], 'apprentice' => ['nullable', 'integer', 'min:1'], 'search' => ['nullable', 'string', 'max:250'], 'plan_date' => ['nullable', 'date_format:Y-m-d']]);
        if ($r->filled('group')) {
            abort_unless($groups->contains('id', (int) $input['group']), 404);
            $sessions = $sessions->where('tutor_group_id', (int) $input['group']);
        }
        if ($r->filled('apprentice')) {
            abort_unless(Apprentice::visibleTo($r->user())->whereKey((int) $input['apprentice'])->exists(), 404);
            $sessions = $sessions->filter(fn ($s) => in_array((int) $input['apprentice'], RegisterFollowup::roster($s)['ids'], true));
        }
        $monthlyOutstanding = MonthlyDevelopment::outstanding(
            $r->user(), $groups, $visible['sessions'], isset($input['group']) ? (int) $input['group'] : null,
            isset($input['apprentice']) ? (int) $input['apprentice'] : null
        );
        $search = trim($input['search'] ?? '');
        if ($search !== '') {
            $sessions = $sessions->filter(fn ($s) => mb_stripos($s->name, $search) !== false || mb_stripos($s->group->name, $search) !== false);
        }
        $followup = RegisterFollowup::for($sessions);
        $view = $input['view'] ?? 'all';
        $filtered = ($view === 'all' ? $followup['rows'] : $followup['rows']->where('bucket', $view))->values();
        $page = min(Paginator::resolveCurrentPage(), max(1, (int) ceil($filtered->count() / 25)));
        $registerRows = new LengthAwarePaginator($filtered->forPage($page, 25)->values(), $filtered->count(), 25, $page, ['path' => $r->url(), 'query' => $r->except('page')]);

        $scheduledThisWeek = collect();
        if (! $r->filled('apprentice') && ! $r->filled('search') && $view === 'all') {
            $selectedGroups = $groups->when(isset($input['group']), fn ($all) => $all->where('id', (int) $input['group']));
            $scheduled = $selectedGroups->map(fn ($group) => ['group' => $group, 'occurrence' => \App\Support\WeeklyRegisters::thisWeek($group)])
                ->filter(fn ($row) => $row['occurrence'] !== null);
            if ($scheduled->isNotEmpty()) {
                $opened = TrainingSession::whereIn('tutor_group_id', $scheduled->pluck('group.id'))
                    ->where('regular_week', $scheduled->first()['occurrence']['week'])->pluck('tutor_group_id');
                $memberGroups = Apprentice::whereIn('tutor_group_id', $scheduled->pluck('group.id'))->distinct()->pluck('tutor_group_id');
                $scheduledThisWeek = $scheduled->reject(fn ($row) => $opened->contains($row['group']->id))
                    ->filter(fn ($row) => $memberGroups->contains($row['group']->id)
                        && \App\Support\RegisterCreation::allowed($r->user(), $row['group']))
                    ->sortBy(fn ($row) => [$row['occurrence']['date'], $row['occurrence']['settings']['start'], $row['group']->name])->values();
            }
        }
        $cancelledHistories = $view === 'cancelled' && $filtered->isNotEmpty()
            ? TrainingSession::whereIn('id', $filtered->pluck('session.id'))->get(['id', 'history'])->keyBy('id')
            : collect();
        $cancellationCounts = $view === 'cancelled' ? $filtered->countBy(function (array $row) use ($cancelledHistories) {
            $event = collect($cancelledHistories->get($row['session']->id)?->history ?? [])->last(
                fn ($item) => ($item['event'] ?? null) === 'session_cancelled'
            );

            return \App\Support\ReportedChoices::REGISTER_CANCELLATION[$event['cancellation_reason_code'] ?? '']
                ?? 'No standard reason recorded';
        })->sortKeys() : collect();
        $activeGroups = $groups->where('active', true);
        $plannerGroup = $groups->firstWhere('id', (int) $r->old('tutor_group_id',
            $input['group'] ?? ($activeGroups->count() === 1 ? $activeGroups->first()->id : 0)));
        $plannerDate = $r->old('session_date', $input['plan_date'] ?? today()->toDateString());
        $plannerDate = is_string($plannerDate) ? $plannerDate : '';
        $plannerOptions = $plannerGroup?->active && RegisterCreation::allowed($r->user(), $plannerGroup)
            ? $this->sessionOptions($plannerGroup, preg_match('/^\d{4}-\d{2}-\d{2}$/D', $plannerDate) && strtotime($plannerDate) ? $plannerDate : null)
            : ['subjects' => [], 'timing' => null];

        return view('attendance.index', ['cancellationCounts' => $cancellationCounts, 'plannerDate' => $plannerDate, 'plannerGroupId' => $plannerGroup?->id, 'plannerOptions' => $plannerOptions, 'scheduledThisWeek' => $scheduledThisWeek, 'nextScope83' => Workday83::registerScope($input), 'nextRegister83' => Workday83::nextRegister($r->user(), null, $input), 'groups' => $groups, 'sessions' => $registerRows->getCollection()->pluck('session'), 'weeklyGroupSessions' => $sessions->whereNotNull('regular_week')->groupBy('tutor_group_id'), 'monthlyOutstanding' => $monthlyOutstanding, 'todayRegisterRows' => $followup['rows']->filter(fn ($row) => $row['session']->session_date->isToday())->values(), 'registerRows' => $registerRows, 'registerCounts' => $followup['counts'], 'registerView' => $view, 'registerSearch' => $search]);
    }

    public function planOptions(Request $r, TutorGroup $group): JsonResponse
    {
        abort_unless($group->active && RegisterCreation::allowed($r->user(), $group), 403);
        $date = $r->validate(['date' => ['nullable', 'date_format:Y-m-d']])['date'] ?? null;
        return response()->json($this->sessionOptions($group, $date))
            ->header('Cache-Control', 'no-store, private');
    }

    private function sessionOptions(TutorGroup $group, ?string $date): array
    {
        $members = Apprentice::where('tutor_group_id', $group->id)->get();
        $timing = $date ? SessionPlanning::weekly($group, $date)['settings'] ?? null : null;

        return [
            'subjects' => array_values(SessionPlanning::options($members, $group)),
            'timing' => $timing ? Arr::only($timing, ['start', 'end', 'breaks']) : null,
        ];
    }

    private function planFromSubjects(Request $r): RedirectResponse
    {
        $data = $r->validate([
            'tutor_group_id' => ['required', 'integer'],
            'session_date' => ['required', 'date_format:Y-m-d'],
            'subjects' => ['required_without:other_subject', 'nullable', 'array', 'list', 'min:1', 'max:50'],
            'other_subject' => ['required_without:subjects', 'nullable', 'string', 'max:250'],
            'subjects.*' => ['required', 'string', 'distinct', 'max:120'],
            'timing' => ['required', Rule::in(['weekly', 'custom'])],
            'start_time' => ['exclude_unless:timing,custom', 'required', 'date_format:H:i'],
            'end_time' => ['exclude_unless:timing,custom', 'required', 'date_format:H:i', 'after:start_time'],
            'breaks_checked' => ['exclude_unless:timing,custom', 'accepted'],
            'breaks' => ['exclude_unless:timing,custom', 'nullable', 'array', 'max:10'],
            'breaks.*.start' => ['nullable', 'required_with:breaks.*.end', 'date_format:H:i'],
            'breaks.*.end' => ['nullable', 'required_with:breaks.*.start', 'date_format:H:i'],
        ]);

        $session = DB::transaction(function () use ($r, $data) {
            $actor = User::whereKey($r->user()->id)->lockForUpdate()->firstOrFail();
            $group = TutorGroup::whereKey($data['tutor_group_id'])->lockForUpdate()->firstOrFail();
            $auth = (int) $r->session()->get('auth_version');
            abort_unless($actor->auth_version === $auth && $group->active && RegisterCreation::allowed($actor, $group), 403);
            $members = Apprentice::where('tutor_group_id', $group->id)->orderBy('id')->lockForUpdate()->get();
            $options = SessionPlanning::options($members, $group);
            $ids = $data['subjects'] ?? [];
            if (array_diff($ids, array_keys($options))) {
                throw ValidationException::withMessages(['subjects' => 'Select subjects from this group’s current scheme of work or training plan.']);
            }
            $subjects = array_map(fn ($id) => Arr::only($options[$id], ['subjectId', 'label', 'area']), $ids);
            $labels = array_column($subjects, 'label');
            if (filled($data['other_subject'] ?? null)) {
                $labels[] = $data['other_subject'];
            }

            $weekly = $data['timing'] === 'weekly' ? SessionPlanning::weekly($group, $data['session_date']) : null;
            if ($data['timing'] === 'weekly' && !$weekly) {
                throw ValidationException::withMessages(['timing' => 'Weekly times are unavailable for this date. Check the schedule or use different times.']);
            }
            $timing = $weekly['settings'] ?? ['start' => $data['start_time'], 'end' => $data['end_time']];
            $breaks = $weekly ? $timing['breaks'] : array_values(array_filter($data['breaks'] ?? [], fn ($break) => filled($break['start'] ?? null)));
            if ($weekly && TrainingSession::where('tutor_group_id', $group->id)->where('regular_week', $weekly['week'])->exists()) {
                throw ValidationException::withMessages(['session_date' => 'A weekly register already exists. Open it from Weekly group registers.']);
            }
            $session = RegisterCreation::store($actor, $auth, $group, [
                'name' => Str::limit($labels[0].(count($labels) > 1 ? ' + '.(count($labels) - 1).' more' : ''), 250),
                'planned_learning' => implode("\n", $labels),
                'session_date' => $data['session_date'],
                'start_time' => $timing['start'],
                'end_time' => $timing['end'],
            ], $breaks);
            $session->history = [[
                'event' => 'session_planned_subjects', 'at' => now()->toIso8601String(),
                'by' => $actor->id, 'subjects' => $subjects, 'other_subject' => $data['other_subject'] ?? null,
            ]];
            $session->regular_week = $weekly['week'] ?? null;
            $session->save();

            return $session;
        }, 3);

        return to_route('attendance.show', $session)->with('status', 'Session planned.');
    }

    public function create(Request $r): RedirectResponse
    {
        abort_unless(Access::manager($r->user()) || $r->user()->role === 'tutor', 403);
        if ($r->input('planner') === 'subjects') {
            return $this->planFromSubjects($r);
        }
        $data = $r->validate(['repeat_weeks' => ['nullable', 'integer', 'min:1', 'max:52'], 'tutor_group_id' => ['required', 'integer', Rule::exists('tutor_groups', 'id')->where('active', true)], 'name' => ['required', 'string', 'max:250'], 'session_date' => ['required', 'date_format:Y-m-d'], 'start_time' => ['required', 'date_format:H:i'], 'end_time' => ['required', 'date_format:H:i', 'after:start_time'], 'planned_learning' => ['required', 'string', 'max:10000'], 'breaks_checked' => ['accepted'], 'breaks' => ['nullable', 'array', 'max:10'], 'breaks.*.start' => ['nullable', 'required_with:breaks.*.end', 'date_format:H:i'], 'breaks.*.end' => ['nullable', 'required_with:breaks.*.start', 'date_format:H:i']]);
        $group = TutorGroup::findOrFail($data['tutor_group_id']);
        abort_unless($this->groupAllowed($r, $group), 403);
        $members = Apprentice::where('tutor_group_id', $group->id)->orderBy('id')->get();
        if ($members->isEmpty()) {
            throw ValidationException::withMessages(['tutor_group_id' => 'This group has no apprentices. Assign members first.']);
        }
        if (! Access::manager($r->user()) && $members->contains(fn ($a) => $a->tutor_id !== $r->user()->id)) {
            throw ValidationException::withMessages(['tutor_group_id' => 'Some group members have a different main tutor. An administrator must check this roster.']);
        }
        $breaks = array_values(array_filter($data['breaks'] ?? [], fn ($b) => filled($b['start'] ?? null) || filled($b['end'] ?? null)));
        foreach ($breaks as $b) {
            if ($b['start'] < $data['start_time'] || $b['end'] > $data['end_time'] || $b['end'] <= $b['start']) {
                throw ValidationException::withMessages(['breaks' => 'Excluded periods must be within the session and end after they start.']);
            }
        }
        $s = DB::transaction(function () use ($r, $data, $breaks, $group) {
            $currentActor = User::whereKey($r->user()->id)->lockForUpdate()->firstOrFail();
            abort_unless($currentActor->active && $currentActor->auth_version === (int) $r->session()->get('auth_version'), 403);
            $locked = TutorGroup::whereKey($group->id)->lockForUpdate()->firstOrFail();
            abort_unless($locked->active && $this->groupAllowed($r, $locked), 403);
            $current = Apprentice::where('tutor_group_id', $group->id)->orderBy('id')->lockForUpdate()->get();
            if ($current->isEmpty() || (! Access::manager($r->user()) && $current->contains(fn ($a) => $a->tutor_id !== $r->user()->id))) {
                throw ValidationException::withMessages(['tutor_group_id' => 'The roster changed. Check the group before planning.']);
            }
            $first = null;
            for ($week = 0; $week < ($data['repeat_weeks'] ?? 1); $week++) {
                $date = Carbon::parse($data['session_date'])->addWeeks($week)->toDateString();
                if (TrainingSession::where('tutor_group_id', $group->id)->whereDate('session_date', $date)->where('state', '!=', 'Cancelled')->where('start_time', '<', $data['end_time'])->where('end_time', '>', $data['start_time'])->exists()) {
                    throw ValidationException::withMessages(['session_date' => 'A planned session already overlaps this group on '.$date.'. No sessions in this request were saved.']);
                }
                $s = RegisterCreation::store($r->user(), (int) $r->session()->get('auth_version'), $locked, array_merge($data, ['session_date' => $date]), $breaks);
                $first ??= $s;
            }

            return $first;
        });

        return to_route('attendance.show', $s)->with('status', 'Session plan saved. Repeated weeks keep separate registers; no attendance, delivery or OTJ hours inferred.');
    }

    public function show(Request $r, TrainingSession $session): View
    {
        if (! RegisterFollowup::roster($session)['shape']) {
            abort_unless(Access::manager($r->user()), 403);
            abort(422, 'The saved roster needs an administrator source check before this register can open.');
        }
        abort_unless($this->sessionAllowed($r, $session), 403);

        $session->load('marks');
        $readiness = RegisterFollowup::readiness($session);
        abort_unless($readiness['can_open'], 422, 'The saved session timing needs an administrator source check before this register can open.');
        $members = Apprentice::whereIn('id', $session->members)->orderBy('last_name')->get();
        $month = $session->session_date->format('Y-m');
        $monthlyCards = MonthlyScorecard::whereIn('apprentice_id', $members->pluck('id'))->where('month', $month)->get()->keyBy('apprentice_id');
        $training = RegisterTrainingRecord::find($session->id)?->details ?? [];
        $deliveryDraft = !$training && $session->marks->isEmpty() && $session->delivered_learning === null
            ? ($session->planned_subjects ?? []) : [];

        return view('attendance.session', [
            'session' => $session,
            'nextScope83' => Workday83::registerScope($r->query()),
            'nextRegister83' => Workday83::nextRegister($r->user(), $session->id, Workday83::registerScope($r->query())),
            'structuredTraining' => $training,
            'trainingOptions' => SessionPlanning::deliveryOptions($session, $members, $training),
            'deliveryDraft' => $deliveryDraft,
            'plannedOtherSubject' => !$training && $session->marks->isEmpty() && $session->delivered_learning === null
                ? collect($session->history ?? [])->firstWhere('event', 'session_planned_subjects')['other_subject'] ?? null : null,
            'members' => $members,
            'registerReadiness' => $readiness,
            'monthlyCards' => $monthlyCards,
            'monthlyDevelopment' => $monthlyCards,
            'sessionMonth' => $month,
        ]);
    }

    public function save(Request $r, TrainingSession $session): RedirectResponse
    {
        abort_unless($this->sessionAllowed($r, $session), 403);
        $nextScope83 = Workday83::registerScope($r->input('next_scope', []));
        $data = $r->validate(['version' => ['required', 'integer'], 'correction_reason' => ['nullable', 'string', 'max:2000'], 'delivered_learning' => ['nullable', 'string', 'max:10000'], 'marks' => ['required', 'array'], 'marks.*.code' => ['nullable', Rule::in(array_keys(Math::CODES))], 'marks.*.arrival' => ['nullable', 'date_format:H:i'], 'marks.*.departure' => ['nullable', 'date_format:H:i'], 'marks.*.otj_confirmed' => ['nullable', 'boolean'], 'marks.*.minutes' => ['nullable', 'integer', 'min:0'], 'marks.*.comment' => ['nullable', 'string', 'max:2000'], 'marks.*.adjustment' => ['nullable', 'string', 'max:2000'], 'monthly_dev' => ['nullable', 'array']]);
        $r->validate(array_fill_keys(['training_subjects', 'training_coverage', 'training_confirmed', 'training_custom', 'training_custom_coverage', 'training_note', 'training_exceptions'], ['prohibited']));
        $training = $r->validate([
            'training' => ['sometimes', 'array:vocational,functional_skills,themes,exceptions,subjects,topic_exceptions,topic_exceptions_present'],
            'training.subjects' => ['nullable', 'array', 'list', 'max:300'],
            'training.subjects.*' => ['required', 'string', 'max:120'],
            'training.topic_exceptions_present' => ['nullable', 'boolean'],
            'training.topic_exceptions' => ['nullable', 'array'],
            'training.topic_exceptions.*' => ['array', 'max:300'],
            'training.topic_exceptions.*.*' => ['nullable', 'in:missed,partial'],
            'training.vocational' => ['nullable', 'string', 'max:3000'],
            'training.functional_skills' => ['nullable', 'string', 'max:3000'],
            'training.themes' => ['nullable', 'string', 'max:3000'],
            'training.exceptions' => ['nullable', 'array'],
            'training.exceptions.*' => ['array:vocational,functional_skills,themes'],
            'training.exceptions.*.*' => ['nullable', 'in:missed,partial'],
        ])['training'] ?? null;
        $selection = $r->validate([
            'development' => ['nullable', 'array'],
            'development.*' => ['array:enabled,version,ratings,comment'],
            'development.*.enabled' => ['nullable', 'boolean'],
        ]);
        $monthly = array_filter($selection['development'] ?? [], static fn (array $entry): bool => (bool) ($entry['enabled'] ?? false));
        foreach (array_keys($monthly) as $id) {
            if (! is_int($id) || $id <= 0) {
                throw ValidationException::withMessages(['development' => 'A snapshot was supplied for someone outside this roster.']);
            }
            $prefix = 'development.'.$id.'.';
            $areas = array_keys(ApprenticeReviewData::DEVELOPMENT_AREAS);
            $rules = [
                $prefix.'version' => ['required', 'integer', 'min:0'],
                $prefix.'ratings' => ['required', 'array:'.implode(',', $areas)],
                $prefix.'comment' => ['nullable', 'string', 'max:1000'],
            ];
            foreach ($areas as $area) {
                $rules[$prefix.'ratings.'.$area] = ['required', Rule::in(ApprenticeReviewData::RATINGS)];
            }
            $monthly[$id] = $r->validate($rules)['development'][$id];
        }
        DB::transaction(function () use ($r, $session, $data, $monthly, $training): void {
            $s = TrainingSession::whereKey($session->id)->lockForUpdate()->firstOrFail();
            $this->version($r, $s);
            if ($s->state === 'Cancelled' || ! $s->ended()) {
                throw ValidationException::withMessages(['marks' => 'Save the actual register after this session has ended. Cancelled sessions cannot be marked.']);
            }
            $ids = $s->members;
            sort($ids);
            $apprentices = Apprentice::whereIn('id', $ids)->orderBy('id')->lockForUpdate()->get()->keyBy('id');
            if (array_diff(array_keys($monthly), $apprentices->keys()->all())) {
                throw ValidationException::withMessages(['development' => 'A snapshot was supplied for someone outside this roster.']);
            }
            if (array_diff(array_map('intval', array_keys($data['marks'])), $ids)) {
                throw ValidationException::withMessages(['marks' => 'A mark was supplied for someone outside this roster.']);
            }
            abort_unless($this->sessionAllowed($r, $s), 403);
            $trainingRecord = RegisterTrainingRecord::whereKey($s->id)->lockForUpdate()->first();
            if ($training !== null && empty($training['topic_exceptions_present']) && ! isset($training['topic_exceptions'])) {
                $prior = $trainingRecord?->details['topicExceptions'] ?? [];
                $selections = array_flip($training['subjects'] ?? []);
                $training['topic_exceptions'] = array_map(fn ($topics) => array_intersect_key($topics, $selections), $prior);
            }
            $details = $training === null ? $trainingRecord?->details : RegisterTraining::details($training, $ids, empty($training['subjects']) ? []
                : SessionPlanning::deliveryOptions($s, $apprentices, $trainingRecord?->details ?? []));
            $trainingChanged = $details !== null && ($trainingRecord
                ? $trainingRecord->details !== $details : (bool) $details['items']);
            if ($trainingChanged && $s->regular_week === null) {
                $r->validate(['weekly_confirmed' => ['accepted']]);
            }
            $before = $s->marks()->get()->toArray();
            $writes = [];
            foreach ($ids as $id) {
                $v = $data['marks'][$id] ?? [];
                $code = $v['code'] ?? null;
                $confirmed = (bool) ($v['otj_confirmed'] ?? false);
                $parts = [];
                $minutes = null;
                if ($confirmed && $details && array_diff(array_column($details['items'], 'area'), ['vocational'])
                    && ! isset($v['minutes'])) {
                    throw ValidationException::withMessages(['marks.'.$id.'.minutes' => 'Enter eligible OTJ minutes separately for mixed training.']);
                }
                if (in_array($code, ['O', 'T'], true)) {
                    if (! filled($v['arrival'] ?? null) || ! filled($v['departure'] ?? null) || $v['departure'] <= $v['arrival']) {
                        throw ValidationException::withMessages(['marks' => 'Record actual arrival and departure for attended training.']);
                    }$parts = Math::parts($v['arrival'], $v['departure'], $s->start_time, $s->end_time, $s->breaks);
                    if (! $parts) {
                        throw ValidationException::withMessages(['marks' => 'Attendance must overlap the session and leave eligible time after exclusions.']);
                    }
                    if ($confirmed) {
                        if (! filled($data['delivered_learning'] ?? null)) {
                            throw ValidationException::withMessages(['delivered_learning' => 'Record the eligible learning delivered before confirming OTJ.']);
                        }$proposal = array_sum(array_map(fn ($p) => $p[1] - $p[0], $parts));
                        $minutes = filled($v['minutes'] ?? null) ? (int) $v['minutes'] : $proposal;
                        if ($minutes > $proposal) {
                            throw ValidationException::withMessages(['marks' => 'Confirmed minutes cannot exceed actual eligible attendance.']);
                        }if ($minutes < $proposal && ! filled($v['adjustment'] ?? null)) {
                            throw ValidationException::withMessages(['marks' => 'Explain the reduction from proposed eligible minutes.']);
                        }
                        if ($minutes > 0) {
                            $extras = ExtraTraining::where('apprentice_id', $id)->whereDate('training_date', $s->session_date)->whereNull('withdrawn_at')->whereNotNull('approval')->get();
                            foreach ($extras as $extra) {
                                if (Math::overlap($parts, $extra->parts)) {
                                    throw ValidationException::withMessages(['marks' => 'This time overlaps approved extra-training OTJ for the apprentice.']);
                                }
                            }
                        }
                        $existing = AttendanceMark::with('session')->where('apprentice_id', $id)->where('otj_confirmed', true)->where('otj_minutes', '>', 0)->where('training_session_id', '!=', $s->id)->whereHas('session', fn ($q) => $q->whereDate('session_date', $s->session_date)->where('state', '!=', 'Cancelled'))->get();
                        foreach ($existing as $other) {
                            if ($minutes > 0 && Math::overlap($parts, $other->parts ?? [])) {
                                throw ValidationException::withMessages(['marks' => 'This time overlaps another confirmed OTJ entry for the apprentice.']);
                            }
                        }
                    }
                } else {
                    if ($confirmed || (int) ($v['minutes'] ?? 0) > 0) {
                        throw ValidationException::withMessages(['marks' => 'This attendance code cannot generate OTJ hours.']);
                    }$minutes = $code ? 0 : null;
                }
                $writes[$id] = ['code' => $code, 'arrival' => in_array($code, ['O', 'T'], true) ? $v['arrival'] : null, 'departure' => in_array($code, ['O', 'T'], true) ? $v['departure'] : null, 'parts' => $parts, 'otj_minutes' => $minutes, 'otj_confirmed' => $confirmed, 'recorded_by' => $r->user()->id, 'comment' => $v['comment'] ?? null, 'adjustment' => $v['adjustment'] ?? null];
            }
            if ($s->regular_week !== null) {
                foreach ($writes as $id => $mark) {
                    if (! $mark['code']) {
                        throw ValidationException::withMessages(['marks.'.$id.'.code' => 'Mark every apprentice before submitting the weekly register.']);
                    }
                }
                $r->validate(['weekly_confirmed' => ['accepted']]);
            }
            $unchanged = ! $trainingChanged && $before && RegisterPayload::same($before, $writes, $s->delivered_learning, $data['delivered_learning'] ?? null);
            if (! $unchanged) {
                if ($before && ! filled($data['correction_reason'] ?? null)) {
                    throw ValidationException::withMessages(['correction_reason' => 'Explain the correction to this saved register.']);
                }
                foreach ($writes as $id => $values) {
                    AttendanceMark::updateOrCreate(['training_session_id' => $s->id, 'apprentice_id' => $id], $values);
                }
                $h = $s->history ?? [];
                $h[] = ['event' => 'register_saved', 'by' => $r->user()->id, 'at' => now()->toIso8601String(), 'correction_reason' => $data['correction_reason'] ?? null, 'before_marks' => $before, 'before_delivered' => $s->delivered_learning];
                $s->history = $h;
                $s->delivered_learning = $data['delivered_learning'] ?? null;
                $s->version++;
                $s->save();
                $this->audit($r, $s, 'register_saved');
            }
            if ($details !== null) {
                RegisterTraining::save($trainingRecord, $s, $details, $r->user()->id, $data['correction_reason'] ?? null);
            }
            foreach ($ids as $id) {
                if (isset($monthly[$id])) {
                    $entry = $monthly[$id];
                    MonthlyDevelopment::save($r->user(), $apprentices[$id], $s, (int) $entry['version'], $entry['ratings'], $entry['comment'] ?? '');
                }
            }
            foreach ((array) ($data['monthly_dev'] ?? []) as $aid => $payload) {
                if (empty($payload['save'])) {
                    continue;
                }
                $target = Apprentice::findOrFail((int) $aid);
                try {
                    MonthlyDevelopment::save(
                        $r->user(),
                        $target,
                        $s,
                        (int) ($payload['version'] ?? 0),
                        (array) ($payload['ratings'] ?? []),
                        (string) ($payload['comment'] ?? '')
                    );
                } catch (\InvalidArgumentException $ex) {
                    throw ValidationException::withMessages(['development' => $ex->getMessage()]);
                }
            }
        });

        if ($r->input('after_save') === 'next') {
            $next = Workday83::nextRegister($r->user(), $session->id, $nextScope83);

            if ($next) {
                return to_route('attendance.show', ['session'=>$next['session'], ...$nextScope83, ...Workday83::returnParameters($r)])
                    ->with('status', 'Register saved.');
            }

            return redirect()->to(Workday83::returnUrl($r) ?? route('attendance.index', $nextScope83))
                ->with('status', 'Register saved. No further due registers.');
        }

        return back()->with('status', 'Register saved. Only explicitly confirmed eligible minutes enter the session OTJ subtotal.');
    }

    public function cancel(Request $r, TrainingSession $session): RedirectResponse
    {
        abort_unless($this->sessionAllowed($r, $session), 403);
        $data = $r->validate([
            'version' => ['required', 'integer'],
            'cancellation_reason_code' => ['nullable', Rule::in(array_keys(\App\Support\ReportedChoices::REGISTER_CANCELLATION))],
            'reason' => ['nullable', 'required_without:cancellation_reason_code', 'required_if:cancellation_reason_code,other', 'string', 'max:2000'],
        ]);
        $code = $data['cancellation_reason_code'] ?? null;
        $reason = $data['reason'] ?? \App\Support\ReportedChoices::REGISTER_CANCELLATION[$code];
        $cancelled = DB::transaction(function () use ($r, $session, $code, $reason): bool {
            $s = TrainingSession::whereKey($session->id)->lockForUpdate()->firstOrFail();
            abort_unless($this->sessionAllowed($r, $s), 403);
            if ($s->state === 'Cancelled') {
                return false;
            }
            $this->version($r, $s);
            $h = $s->history ?? [];
            $h[] = ['event' => 'session_cancelled', 'by' => $r->user()->id, 'at' => now()->toIso8601String(), 'reason' => $reason, 'cancellation_reason_code' => $code, 'before_state' => $s->state];
            $s->history = $h;
            $s->state = 'Cancelled';
            $s->cancellation_reason = $reason;
            $s->version++;
            $s->save();
            $this->audit($r, $s, 'cancelled');

            return true;
        });

        return back()->with('status', $cancelled
            ? 'Session cancelled. Attendance and OTJ totals exclude this session.'
            : 'This session was already cancelled.');
    }

    public function apprentice(Request $r, Apprentice $apprentice): View
    {
        abort_unless(Access::training($r->user(), $apprentice), 403);
        $ledger = AttendanceLedger::forIds([$apprentice->id])[$apprentice->id];

        return view('attendance.apprentice', ['apprentice' => $apprentice, 'otjHeadline' => \App\Support\OtjPosition::headline(\App\Support\OtjPosition::forApprentice($apprentice)), ...$ledger]);
    }

    private function version(Request $r, TrainingSession $s): void
    {
        if ($s->version !== $r->integer('version')) {
            throw ValidationException::withMessages(['version' => 'This session changed. Reopen before saving.']);
        }
    }

    private function audit(Request $r, TrainingSession $s, string $event): void
    {
        foreach ($s->members as $id) {
            DB::table('learning_events')->insert(['user_id' => $r->user()->id, 'apprentice_id' => $id, 'kind' => 'session', 'record_id' => $s->id, 'event' => $event, 'created_at' => now()]);
        }
    }
}

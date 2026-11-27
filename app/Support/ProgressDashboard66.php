<?php

namespace App\Support;

use App\Models\Apprentice;
use App\Models\CohortForecast;
use App\Models\EmployerApprenticeGrant;
use App\Models\EmployerPortalAction;
use App\Models\LearningTask;
use App\Models\Qualification;
use App\Models\ReviewAppointment;
use App\Models\SmartAction;
use App\Models\SupportConcern;
use App\Models\SupportPlan;
use App\Models\TrainingTopic;
use App\Models\TutorGroup;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Http\Request;

/** Read-only projections. No role supplied by the browser can select a live scope. */
class ProgressDashboard66
{
    public const ROLES = ['admin', 'manager', 'tutor', 'apprentice', 'employer', 'mentor'];

    public static function actor(Request $r): User
    {
        return PortalActions::actor($r->user());
    }

    public static function filters(Request $r): array
    {
        $f = $r->validate(['tutor' => 'nullable|integer|min:1', 'group' => 'nullable|integer|min:1', 'from' => 'nullable|date_format:Y-m-d|before_or_equal:today', 'to' => 'nullable|date_format:Y-m-d|before_or_equal:today', 'apprentice' => 'nullable|string|max:40', 'q' => 'nullable|string|max:100', 'programme' => 'nullable|string|max:150', 'metric' => 'nullable|in:all,people,approved,awaiting,overdue,reviews,checks,attendance,otj,pending,responded,registers', 'sort' => 'nullable|in:name,due,approved', 'direction' => 'nullable|in:asc,desc', 'page' => 'nullable|integer|min:1|max:100000']);
        $f = array_filter($f, fn ($v) => $v !== null);
        $f += ['tutor' => null, 'group' => null, 'from' => today()->subDays(83)->toDateString(), 'to' => today()->toDateString(), 'q' => '', 'programme' => '', 'apprentice' => '', 'metric' => 'all', 'sort' => 'name', 'direction' => 'asc', 'page' => 1];
        abort_unless($f['from'] <= $f['to'] && Carbon::parse($f['from'])->diffInDays(Carbon::parse($f['to'])) < 93, 422, 'Choose an attendance period of up to 93 inclusive days.');

        return $f;
    }

    public static function demo(Request $r, string $role): array
    {
        $u = self::actor($r);
        abort_unless(in_array($role, self::ROLES, true) && (LearningAccess::manager($u) || $u->role === $role || ($role === 'mentor' && StaffDuties::has($u, 'mentor'))), 403);
        $f = self::filters($r);
        $path = resource_path('data/progress-demo66.json');
        abort_unless(is_readable($path), 503, 'Demonstration data is unavailable. No saved records have been changed.');
        $file = json_decode(file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
        $people = $file['people'];
        $people = array_values(array_filter($people, fn ($a) => match ($role) {
            'tutor' => $a['tutor'] === 'Fictional Tutor A','apprentice' => LearningAccess::manager($u) ? true : $a['id'] === 'DEMO-01','employer' => $a['employer'] === 'Fictional Workshop A','mentor' => $a['mentor'] === 'Fictional Mentor A',default => true
        }));
        if ($role === 'apprentice' && ! $f['apprentice']) {
            $f['apprentice'] = 'DEMO-01';
        }
        foreach ($people as &$a) {
            $a['group_id'] = $a['group'] === 'Fictional Group A' ? 1 : 2;
            $a['attendance_rows'] = array_values(array_filter($a['attendance_rows'], fn ($v) => $v['date'] >= $f['from'] && $v['date'] <= $f['to']));
            $a['attendance'] = AttendanceMath::report($a['attendance_rows']);
            $a['url'] = route('progress66.demo', ['role' => $role, 'apprentice' => $a['id']]).'#records';
            $a['next_url'] = $a['url'];
            $a['review_url'] = $a['url'];
            $a['blocks'] = self::blocks($a['task_status']);
            if ($a['gateway_recorded'] ?? false) {
                $a['blocks'][10]['complete'] = true;
                $a['blocks'][10]['status'] = 'Recorded Gateway decision — fictional';
            }if ($a['epa_recorded'] ?? false) {
                $a['blocks'][11]['complete'] = true;
                $a['blocks'][11]['status'] = 'EPA achievement recorded — fictional';
            }$a['appointment'] = $a['review_due'] && $a['review_due'] >= $file['as_of'] ? '24 Nov 2026 10:00 — fictional booking' : 'Not recorded';
            $a['next_title'] = 'Continue your next available task';
            $a['roadmap'] = in_array($role, ['employer', 'mentor']) ? null : ApprenticeRoadmap67::compose($a['blocks'], $a['roadmap_events'] ?? [], $file['as_of'], (bool) ($a['epa_recorded'] ?? false));
            $a['milestones'] = array_values(array_filter($a['roadmap']['events'] ?? [], fn ($e) => $e['status'] === 'Recorded'));
            unset($a['roadmap_events']);
            if (in_array($role, ['employer', 'mentor'])) {
                $a['feedback'] = [];
                $a['checks'] = [];
                $a['topics'] = [];
                $a['actions'] = array_values(array_filter($a['actions'], fn ($v) => $v['kind'] === 'workplace'));
            }
        }unset($a);
        $result = self::assemble($people, $f, $role, true, ['as_of' => $file['as_of'], 'notice' => 'Separate read-only demonstration. Nothing here writes records, sends reminders or enters a live submission.', 'task_history' => true]);
        if ($role === 'admin') {
            $result['registers'] = [];
            foreach ($result['rows'] as $person) {
                foreach ($person['attendance_rows'] as $attendance) {
                    if ($attendance['code'] === null) {
                        $result['registers'][] = ['title' => 'Fictional register awaiting marks', 'date' => $attendance['date'], 'state' => 'needs-register', 'url' => $person['url']];
                    }
                }
            }$result['metrics'][3] = ['key' => 'registers', 'label' => 'Registers needing attention', 'value' => count($result['registers']), 'unit' => 'registers', 'note' => 'Fictional saved-roster register with an unmarked attendance record'];
        }if ($role === 'apprentice' && LearningAccess::manager($u)) {
            $result['demo_cases'] = array_map(fn ($a) => collect($a)->only('id', 'name', 'scenario')->all(), $people);
        }

return $result;
    }

    public static function live(Request $r): array
    {
        $u = self::actor($r);
        $f = self::filters($r);
        abort_unless(in_array($u->role, ['admin', 'ceo', 'manager', 'tutor', 'apprentice', 'employer', 'mentor'], true) || StaffDuties::has($u, 'mentor'), 403);
        $role = $u->role === 'ceo' ? 'manager' : $u->role;
        if (! in_array($role, self::ROLES, true) && StaffDuties::has($u, 'mentor')) {
            $role = 'mentor';
        }if ($u->role === 'manager' && ! CentreManagerAccess::workspace($u) && StaffDuties::has($u, 'mentor')) {
            $role = 'mentor';
        }
        if ($f['tutor']) {
            abort_unless(in_array($u->role, ['admin', 'ceo'], true) || ($u->role === 'tutor' && (int) $f['tutor'] === $u->id), 403);
        }
        if (in_array($role, ['employer', 'mentor', 'manager'], true) && $u->role !== 'ceo') {
            return self::limited($u, $f, $role);
        }
        $query = Apprentice::visibleTo($u)->with(['groupRecord', 'tutor', 'programmeRecord']);
        if ($f['tutor']) {
            abort_unless((clone $query)->where('tutor_id', $f['tutor'])->exists(), 404);
            $query->where('tutor_id', $f['tutor']);
        }
        if ($f['group']) {
            abort_unless((clone $query)->where('tutor_group_id', $f['group'])->exists(), 404);
            $query->where('tutor_group_id', $f['group']);
        }
        if ($u->role === 'apprentice') {
            abort_unless(ApprenticeExperience::profile($u), 409, 'Ask GTA to check your single linked apprentice profile.');
        }
        if ($f['apprentice']) {
            abort_unless(ctype_digit($f['apprentice']) && (clone $query)->whereKey($f['apprentice'])->exists(), 404);
            $query->whereKey($f['apprentice']);
        }
        $people = $query->orderBy('id')->get();

        return ReportRead85::run($people, function () use ($people, $u, $f, $role) {
            $ids = $people->pluck('id');
            $ledger = AttendanceLedger::forIds($ids->all(), false);
            $tasks = LearningTask::whereIn('apprentice_id', $ids)
                ->get(['id', 'apprentice_id', 'reference', 'block', 'title', 'status', 'due_date', 'submitted_at', 'template->kind as summary_kind', 'template->catalogueId as summary_catalogue'])
                ->each(function (LearningTask $task): void {
                    $template = [];
                    if ($task->summary_kind !== null) {
                        $template['kind'] = $task->summary_kind;
                    }
                    if ($task->summary_catalogue !== null) {
                        $template['catalogueId'] = $task->summary_catalogue;
                    }
                    $task->setAttribute('template', $template);
                })
                ->groupBy('apprentice_id');
            $referredSubmissions = LearningTask::whereIn('apprentice_id', $ids)
                ->where('status', 'Referred')
                ->get(['id', 'submissions'])
                ->keyBy('id');
            $topics = TrainingTopic::whereIn('apprentice_id', $ids)->get()->groupBy('apprentice_id');
            $quals = Qualification::whereIn('apprentice_id', $ids)->get()->groupBy('apprentice_id');
            $actions = SmartAction::with(['task' => fn ($q) => $q->select('id', 'apprentice_id', 'reference', 'block', 'title', 'status', 'due_date', 'submitted_at')])->whereIn('apprentice_id', $ids)->get()->groupBy('apprentice_id');
            $rows = [];
            foreach ($people as $a) {
                $set = $tasks->get($a->id, collect());
                $status = collect(PortalVisuals::tasks($set)['rows'])->pluck('value', 'label')->all();
                $known = $set->filter(fn ($t) => ($t->template['kind'] ?? 'source-pending') !== 'source-pending');
                $dated = $known->every(fn ($t) => $t->due_date !== null);
                $expected = $known->count() && $dated ? $known->filter(fn ($t) => $t->due_date->lte(today()))->count() : null;
                $checks = [];
                foreach (['programme_id' => 'Programme link', 'tutor_id' => 'Main tutor', 'employer_id' => 'Employer link', 'tutor_group_id' => 'Tutor group', 'start_date' => 'Start date'] as $field => $label) {
                    if (! $a->$field) {
                        $checks[] = $label.' not recorded';
                    }
                }
                if ($status['Source needs checking']) {
                    $checks[] = 'Task source needs checking';
                }if (! $dated) {
                    $checks[] = 'Some task deadlines not recorded';
                }if ($known->contains(fn ($t) => $t->status === 'Awaiting marking' && (! $t->submitted_at || $t->submitted_at->gt(now())))) {
                    $checks[] = 'Submission dates need checking';
                }
                $attendance = array_values(array_filter($ledger[$a->id]['rows'] ?? [], fn ($v) => $v['date'] >= $f['from'] && $v['date'] <= $f['to']));
                $o = OtjPosition::forApprentice($a);
                $reviewPosition = ReviewReminders::position($a);
                $cycle = $reviewPosition['cycle'];
                if ($reviewPosition['programme_check'] || $reviewPosition['paused']) {
                    $cycle['next_due'] = null;
                    $checks[] = $reviewPosition['paused'] ? 'Review cycle paused for the recorded programme status' : 'Programme status needed to confirm review cycle';
                }
                $topicRows = [];
                foreach (['english', 'maths'] as $subject) {
                 $assigned = $topics->get($a->id, collect())->where('subject', $subject)->filter(fn ($topic) => $topic->planned_hours > 0);
                 $qualifications = $quals->get($a->id, collect())->where('subject', $subject);
                 if ($assigned->isNotEmpty() || ($u->role === 'apprentice' && $qualifications->isNotEmpty())) {
                  $topicRows[$subject] = [
                   'assigned' => $assigned->count(),
                   'completed' => $assigned->where('status', 'Completed')->count(),
                   'qualification' => $qualifications->map(fn ($qualification) => $qualification->title.': '.$qualification->status)->implode('; ') ?: 'Not recorded',
                  ];
                 }
                }
                $act = [];
                foreach ($actions->get($a->id, collect()) as $x) {
                    if ($u->role === 'apprentice' && $x->owner !== 'Apprentice') {
                        continue;
                    }if (in_array($x->currentStatus(), ['Completed', 'With tutor'], true)) {
                        continue;
                    }$act[] = ['title' => $x->title, 'owner' => $x->owner, 'due' => $x->deadline()?->toDateString(), 'state' => $x->currentStatus(), 'kind' => 'action', 'url' => route('actions.index', $a).'#action-'.$x->id];
                }
                foreach ($known->where('status', 'Awaiting marking') as $t) {
                    if ($u->role === 'apprentice') {
                        continue;
                    }$act[] = ['title' => 'Mark '.$t->reference, 'owner' => $a->tutor?->name ?? 'Tutor not assigned', 'due' => $t->submitted_at?->copy()->addDays(7)->toDateString(), 'state' => 'Awaiting marking', 'kind' => 'marking', 'url' => route('tasks.show', $t)];
                }
                if ($cycle['next_due']) {
                    $act[] = ['title' => 'Check progress review due reference', 'owner' => $u->role === 'apprentice' ? 'Tutor' : ($a->tutor?->name ?? 'Tutor not assigned'), 'due' => $cycle['next_due'], 'state' => 'Review due reference', 'kind' => 'review', 'url' => $u->role === 'apprentice' ? route('electronic-reviews.index', ['filter' => 'mine']) : route('reviews.create', $a)];
                }
                $feedback = [];
                foreach ($known->where('status', 'Referred')->take(3) as $t) {
                    $latest = collect($referredSubmissions->get($t->id)?->submissions ?? [])->last();
                    if (isset($latest['decision']['feedback'])) {
                        $feedback[] = ['date' => substr($latest['decision']['date'] ?? '', 0, 10), 'text' => $latest['decision']['feedback']];
                    }
                }
                if ($u->role !== 'apprentice') {
                    $team = SupportAccess::team($u, $a);
                    $concerns = SupportConcern::with('owner:id,name')->where('apprentice_id', $a->id)->where('status', '!=', 'Closed')->when(! $team, fn ($q) => $q->where('raised_by', $u->id))->get(['id', 'apprentice_id', 'owner_id', 'action_due', 'status']);
                    foreach ($concerns as $c) {
                        $act[] = ['title' => 'Check an authorised support follow-up', 'owner' => $c->owner?->name ?? 'Owner not recorded', 'due' => $c->action_due?->toDateString(), 'state' => $c->status, 'kind' => 'support', 'url' => route('support.index', $a)];
                    }
                    if ($team) {
                        $plans = SupportFollowup::plans(SupportPlan::where('apprentice_id', $a->id)->where('status', 'Active')->get(), collect([$a]));
                        foreach ($plans['rows'] as $pr) {
                            $act[] = ['title' => 'Review an authorised support plan', 'owner' => 'Authorised Inclusion Team', 'due' => $pr['due'], 'state' => 'Support review', 'kind' => 'support', 'url' => route('support.index', $a)];
                        }
                    }
                }
                $home = $u->role === 'apprentice' ? ApprenticeExperience::home($a) : null;
                $personal = [];
                if ($home !== null) {
                 $personal['next_step'] = $home['next'];
                 $personal['otj_headline'] = OtjPosition::headline($o);
                 $catalogue = TaskCatalogue::allocationKey($a);
                 if ($catalogue !== null) {
                  $references = array_column(TaskCatalogue::tasks($catalogue), 'id');
                  $approved = $known->where('status', 'Approved')
                   ->filter(fn ($task) => TaskCatalogue::savedKey($task) === $catalogue)
                   ->pluck('reference')->intersect($references)->unique()->count();
                  $personal['programme_tasks'] = ['approved' => $approved, 'total' => count($references)];
                 }
                }

                $forecast = 'Not available';
                $outcome = 'Not recorded';
                if (CohortForecasts::allowed($u, $a)) {
                    $source = CohortForecasts::source($a);
                    $outcome = $source['context']['status'] ?? 'Not recorded';
                    $estimate = CohortForecast::find($a->id);
                    $forecast = $estimate && CohortForecasts::current($estimate, $source) ? 'Estimate '.$estimate->details['target_date'].' — '.$estimate->details['basis'] : ($estimate ? 'Estimate needs review' : 'Not recorded');
                }
                $appointment = class_exists(ReviewNextStep::class) ? ReviewNextStep::for($u, $a)['appointment'] : ReviewAppointment::where('apprentice_id', $a->id)->where('state', 'Published')->where('ends_at', '>', now()->utc())->orderBy('starts_at')->limit(50)->get()->first(fn ($meeting) => ReviewAppointments::published($meeting) && ReviewAppointments::current($meeting) && ReviewAppointments::visible($u, $meeting));

                $roadmap = ($u->role === 'apprentice' || $people->count() === 1) ? ApprenticeRoadmap67::live($u, $a, $home['blocks'] ?? ApprenticeExperience::blocks($a, $set), $o['rows']) : null;
                $rows[] = [...$personal, 'id' => (string) $a->id, 'reference' => $a->reference, 'name' => $a->first_name.' '.$a->last_name, 'group_id' => $a->tutor_group_id, 'programme' => $a->programmeLabel() ?? 'Not recorded', 'group' => $a->groupLabel() ?? 'Not recorded', 'tutor' => $a->tutor?->name ?? 'Not recorded', 'scenario' => '', 'task_status' => $set->count() ? $status : null, 'expected_tasks' => $expected, 'attendance_rows' => $attendance, 'attendance' => AttendanceMath::report($attendance), 'otj' => ['recorded_hours' => count($o['rows']) ? round($o['summary']['recorded_minutes'] / 60, 2) : null, 'target_hours' => $o['summary']['target_hours'], 'planned_due_hours' => $o['summary']['planned_due_hours'], 'pending_sources' => $o['summary']['pending_sources'], 'invalid_sources' => $o['summary']['invalid_sources'], 'delivered_hours' => null, 'approved_extra_hours' => count($o['rows']) ? round($o['summary']['extra_minutes'] / 60, 2) : null], 'review_due' => $cycle['next_due'], 'review_state' => $cycle['status'] ?? 'Not available', 'topics' => $topicRows, 'actions' => $act, 'checks' => $checks, 'feedback' => $feedback, 'roadmap' => $roadmap, 'milestones' => array_values(array_filter($roadmap['events'] ?? [], fn ($e) => $e['status'] === 'Recorded')), 'blocks' => $home['blocks'] ?? ApprenticeExperience::blocks($a, $set), 'next_title' => $home['next']['title'] ?? 'Open your training tasks', 'next_url' => $home['next']['url'] ?? route('learning.index', $a), 'url' => route('progress66.index', ['apprentice' => $a->id]).'#records', 'review_url' => $u->role === 'apprentice' ? route('electronic-reviews.index', ['filter' => 'mine']) : route('reviews.create', $a), 'outcome' => $outcome, 'forecast' => $forecast, 'otj_ledger' => $o['rows'], 'otj_plan' => DeliveryPlan::approved($a)['blocks'] ?? [], 'appointment' => $appointment ? $appointment->starts_at->timezone('Europe/London')->format('d M Y H:i') : 'Not recorded'];
            }
            $notice = 'Task decisions and plan references are current records at capture. The date filter applies to attendance only. No official achievement rate or historical progress curve is inferred.';
            $result = self::assemble($rows, $f, $role, false, ['as_of' => today()->toDateString(), 'notice' => $notice, 'task_history' => false]);
            if ($u->role !== 'apprentice') {
                $selectedIds = array_map('intval', array_column($result['rows'], 'id'));
                $visible = RegisterFollowup::visible($u);
                $sessions = $visible['sessions']->filter(fn ($s) => $s->session_date->toDateString() >= $f['from'] && $s->session_date->toDateString() <= $f['to'] && array_intersect(RegisterFollowup::roster($s)['ids'], $selectedIds));
                $registers = RegisterFollowup::for($sessions);
                $result['registers'] = $registers['rows']->whereIn('bucket', ['needs-register', 'checks'])->map(fn ($v) => ['title' => $v['session']->name, 'date' => $v['session']->session_date->toDateString(), 'state' => $v['bucket'], 'url' => route('attendance.show', $v['session'])])->values()->all();
                if ($role === 'admin') {
                    $result['metrics'][3] = ['key' => 'registers', 'label' => 'Registers needing attention', 'value' => count($result['registers']), 'unit' => 'registers', 'note' => 'Saved rosters in the attendance period; malformed unscoped rosters need the existing register workspace.'];
                }
            }

            return $result;
        });
    }

    private static function limited(User $u, array $f, string $role): array
    {
        abort_unless(! $f['group'] || $role === 'manager', 404);
        $rows = [];
        $notice = '';
        if ($role === 'manager') {
            $policy = CentreManagerAccess::policy($u);
            abort_unless($policy, 403);
            $people = CentreManagerAccess::people($u)->when($f['group'], fn ($q) => $q->where('tutor_group_id', $f['group']))->get();
            $notice = 'Your reviewed groups and operational areas only. Provider-wide results and private support are not granted by a centre-manager role.';
        } elseif ($role === 'mentor') {
            $people = Apprentice::where('mentor_id', $u->id)->get()->filter(fn ($a) => StaffDuties::assigned($u, $a, 'mentor'));
            $notice = 'Existing Learning & Progress Mentor assignment: review information only. External workplace mentors must use checked named employer access; no new role rights are inferred.';
        } else {
            $grants = EmployerApprenticeGrant::where('user_id', $u->id)->where('active', true)->get();
            $valid = $grants->filter(fn ($g) => ($a = Apprentice::find($g->apprentice_id)) && EmployerActions::valid($g, $a));
            $people = Apprentice::whereIn('id', $valid->pluck('apprentice_id'))->get();
            $notice = 'Named, currently verified employer access. Only published shared requests are shown. A request grant does not authorise the full task, attendance, support or careers record.';
        }
        foreach ($people as $a) {
            $status = null;
            $attendance = AttendanceMath::report([]);
            $otj = ['recorded_hours' => null, 'target_hours' => null, 'planned_due_hours' => null];
            $cycle = ['next_due' => null];
            $checks = [];
            $acts = [];
            if ($role === 'manager') {
                foreach (['training', 'attendance', 'reviews'] as $area) {
                    if (! CentreManagerAccess::allows($u, $a, $area)) {
                        continue;
                    }$d = CentreOversight::position($u, $a, $area, $f['from'], $f['to']);
                    $checks = array_merge($checks, array_column(array_values($d['flags']), 'text'));
                    $data = $d['data'];
                    if ($area === 'training' && isset($data['loaded_tasks'])) {
                        $pending = $data['source_pending'];
                        $status = ['Approved' => $data['approved'], 'Awaiting marking' => $data['awaiting_marking'], 'Referred' => null, 'Draft / other' => null, 'Source needs checking' => $pending];
                        $status['Unclassified other tasks'] = $data['loaded_tasks'] - $data['approved'] - $data['awaiting_marking'] - $pending;
                    }
                    if ($area === 'attendance') {
                        $attendance = $data['attendance'] ?? $attendance;
                        $otj = ['recorded_hours' => isset($data['otj']['recorded_minutes']) ? $data['otj']['recorded_minutes'] / 60 : null, 'target_hours' => $data['otj']['target_hours'] ?? null, 'planned_due_hours' => $data['otj']['planned_due_hours'] ?? null];
                    }
                    if ($area === 'reviews') {
                        $cycle = $data['cycle'] ?? $cycle;
                        if (($data['paused'] ?? false) || ($data['programme_status'] ?? '') === 'Unknown / source check' || ($cycle['integrity_checks'] ?? 0) > 0) {
                            $cycle['next_due'] = null;
                        }
                    }
                }
            } elseif ($role === 'mentor') {
                $rp = ReviewReminders::position($a);
                $cycle = $rp['cycle'];
                if ($rp['programme_check'] || $rp['paused']) {
                    $cycle['next_due'] = null;
                }if ($cycle['next_due']) {
                    $acts[] = ['title' => 'Check the next review due reference', 'owner' => 'Learning & Progress Mentor', 'due' => $cycle['next_due'], 'state' => 'Review due reference', 'kind' => 'review', 'url' => route('reviews.create', $a)];
                }
            } else {
                $gs = $valid->where('apprentice_id', $a->id);
                foreach (EmployerPortalAction::whereIn('employer_apprentice_grant_id', $gs->pluck('id'))->where('state', '!=', 'Withdrawn')->get() as $x) {
                    $g = $gs->firstWhere('id', $x->employer_apprentice_grant_id);
                    if (! $g || ! EmployerActions::grantBinding($x, $g)) {
                        continue;
                    }$acts[] = ['title' => $x->title, 'owner' => 'Named employer contact', 'due' => $x->due_date?->toDateString(), 'state' => $x->state, 'kind' => 'request', 'url' => route('employer-actions.show', $x)];
                }
            }
            if (in_array($role, ['employer', 'mentor'], true)) {
                foreach (ReviewAppointment::where('apprentice_id', $a->id)->where('state', 'Published')->where('ends_at', '>', now()->utc())->orderBy('starts_at')->limit(50)->get() as $meeting) {
                    if (ReviewAppointments::published($meeting) && ReviewAppointments::current($meeting) && ReviewAppointments::visible($u, $meeting)) {
                        $acts[] = ['title' => 'Upcoming published review appointment', 'owner' => 'Published participants', 'due' => $meeting->starts_at->timezone('Europe/London')->toDateString(), 'state' => 'Published appointment', 'kind' => 'appointment', 'url' => route('review-appointments.show', $meeting)];
                    }
                }
            }
            $url = $role === 'manager' ? route('centre-oversight.index') : ($role === 'mentor' ? route('reviews.create', $a) : route('employer-actions.index'));
            $rows[] = ['id' => (string) $a->id, 'name' => $a->first_name.' '.$a->last_name, 'reference' => $a->reference, 'group_id' => $role === 'manager' ? $a->tutor_group_id : null, 'programme' => $role === 'employer' ? 'Not shared' : ($a->programmeLabel() ?? 'Not recorded'), 'group' => $role === 'manager' ? ($a->groupLabel() ?? 'Not recorded') : 'Not shared', 'tutor' => 'Not shared', 'task_status' => $status, 'expected_tasks' => null, 'attendance' => $attendance, 'attendance_rows' => [], 'otj' => $otj, 'review_due' => $cycle['next_due'], 'review_state' => 'Due reference, not a booking', 'topics' => [], 'actions' => $acts, 'checks' => $checks, 'feedback' => [], 'milestones' => [], 'blocks' => [], 'next_title' => 'Open your authorised actions', 'next_url' => $url, 'url' => $url, 'review_url' => $url, 'outcome' => 'Not shared', 'forecast' => 'Not available'];
        }

        return self::assemble($rows, $f, $role, false, ['as_of' => today()->toDateString(), 'notice' => $notice, 'task_history' => false, 'limited' => true, 'review_available' => $role !== 'manager' || in_array('reviews', $policy['areas'], true)]);
    }

    public static function blocks(?array $status): array
    {
        $approved = $status['Approved'] ?? 0;
        $rows = [];
        for ($i = 1; $i <= 12; $i++) {
            $done = min(5, max(0, $approved - 5 * ($i - 1)));
            $rows[] = ['number' => $i, 'title' => $i <= 10 ? 'Training block '.$i : ($i === 11 ? 'Gateway' : 'EPA'), 'status' => $i > 10 ? 'Separate sign-off required' : ($status === null ? 'Not recorded' : ($done === 5 ? 'Tasks approved' : ($approved >= 5 * ($i - 1) ? 'Current task position' : 'Upcoming'))), 'approved' => $i <= 10 ? $done : null, 'allocated' => $i <= 10 && $status !== null ? 5 : null, 'complete' => $i <= 10 && $done === 5];
        }

return $rows;
    }

    public static function assemble(array $rows, array $f, string $role, bool $demo, array $meta): array
    {
        // Check identity against the authorised scope before applying any search filter.
        if ($f['apprentice']) {
            abort_unless(collect($rows)->contains(fn ($a) => (string) $a['id'] === (string) $f['apprentice']), 404);
            $rows = array_values(array_filter($rows, fn ($a) => (string) $a['id'] === (string) $f['apprentice']));
        }
        $groupOptions = [];
        foreach ($rows as $person) {
            if (isset($person['group_id'])) {
                $groupOptions[$person['group_id']] = $person['group'];
            }
        }
        if ($f['group']) {
            $rows = array_values(array_filter($rows, fn ($a) => (int) ($a['group_id'] ?? 0) === (int) $f['group']));
        }
        $programmes = array_values(array_unique(array_column($rows, 'programme')));
        sort($programmes);
        $rows = array_values(array_filter($rows, fn ($a) => (! $f['q'] || str_contains(mb_strtolower($a['name'].' '.$a['reference']), mb_strtolower($f['q']))) && (! $f['programme'] || $a['programme'] === $f['programme'])));
        $actions = [];
        $status = [];
        $attended = 0;
        $expected = 0;
        $unmarked = 0;
        $reviewDue = 0;
        $reviewKnown = 0;
        $checks = 0;
        $validPeople = 0;
        $knownTasks = 0;
        $approved = 0;
        $planned = 0;
        $plannedKnown = true;
        $otj = 0;
        $otjPeople = 0;
        foreach ($rows as &$a) {
            $a['approved'] = $a['task_status']['Approved'] ?? null;
            $a['tasks'] = $a['task_status'] === null ? null : array_sum(array_filter($a['task_status'], fn ($v) => $v !== null));
            $a['known_tasks'] = $a['tasks'] === null ? null : $a['tasks'] - ($a['task_status']['Source needs checking'] ?? 0);
            $a['progress'] = $a['known_tasks'] > 0 ? round(100 * $a['approved'] / $a['known_tasks'], 1) : null;
            $a['expected_progress'] = $a['expected_tasks'] !== null && $a['known_tasks'] > 0 ? round(100 * $a['expected_tasks'] / $a['known_tasks'], 1) : null;
            $a['position'] = $a['progress'] === null || $a['expected_progress'] === null ? 'Not available' : ($a['progress'] > $a['expected_progress'] ? 'Ahead of recorded task deadlines' : ($a['progress'] < $a['expected_progress'] ? 'Behind recorded task deadlines' : 'At recorded task deadlines'));
            if (! $demo && $a['progress'] !== null && $a['expected_progress'] !== null) {
                $a['position'] = $a['progress'] > $a['expected_progress'] ? 'Approvals exceed due-task reference' : ($a['progress'] < $a['expected_progress'] ? 'Approval below due-task reference' : 'Approvals match due-task reference');
            }
            if ($a['tasks'] !== null) {
                $validPeople++;
                foreach ($a['task_status'] as $k => $v) {
                    if ($v !== null) {
                        $status[$k] = ($status[$k] ?? 0) + $v;
                    }
                }$approved += $a['approved'];
                $knownTasks += $a['known_tasks'];
            }
            if ($a['tasks'] !== null && $a['expected_tasks'] === null) {
                $plannedKnown = false;
            } elseif ($a['expected_tasks'] !== null) {
                $planned += $a['expected_tasks'];
            }
            $attended += $a['attendance']['attended'];
            $expected += $a['attendance']['expected'];
            $unmarked += $a['attendance']['unmarked'];
            if ($a['otj']['recorded_hours'] !== null) {
                $otj += $a['otj']['recorded_hours'];
                $otjPeople++;
            }
            $due = $a['review_due'];
            if ($due) {
                $reviewKnown++;
            }if ($due && $due < $meta['as_of']) {
                $reviewDue++;
            }
            if (count($a['checks'])) {
                $checks++;
            }
            foreach ($a['actions'] as $i => $x) {
                $x += ['url' => $a['url']];
                $actions[] = $x + ['id' => $a['id'].'-'.$i, 'person_id' => $a['id'], 'name' => $a['name'], 'overdue' => $x['due'] !== null && $x['due'] < $meta['as_of'] && ! in_array($x['state'], ['Completed', 'Responded', 'Confirmed'], true)];
            }
        }unset($a);
        $overdue = ($meta['limited'] ?? false) && $role === 'manager' ? null : count(array_filter($actions, fn ($x) => $x['overdue']));
        $waiting = $validPeople ? ($status['Awaiting marking'] ?? 0) : null;
        $attendance = $expected && ! $unmarked ? round(100 * $attended / $expected, 1) : null;
        $progress = $knownTasks ? round(100 * $approved / $knownTasks, 1) : null;
        if (($meta['review_available'] ?? true) === false || (count($rows) > 0 && $reviewKnown === 0)) {
            $reviewDue = null;
        }
        $m = fn ($key, $label, $value, $unit, $note) => compact('key', 'label', 'value', 'unit', 'note');
        $metrics = [$m('people', $role === 'apprentice' ? 'Allocated tasks' : 'Authorised apprentices', $role === 'apprentice' ? ($rows[0]['tasks'] ?? null) : count($rows), $role === 'apprentice' ? 'tasks' : 'people', 'Current filtered scope'), $m('approved', 'Training tasks approved', $progress, '%', 'Known-source tasks only; not overall apprenticeship achievement'), $m('awaiting', 'Awaiting tutor marking', $waiting, 'tasks', 'Submitted work is separate from approved work'), $m('attendance', 'Attendance', $attendance, '%', $f['from'].' to '.$f['to'].'; '.$attended.'/'.$expected.' expected apprentice-session records; '.$unmarked.' unmarked'), $m('reviews', 'Overdue review references', $reviewDue, 'reviews', 'Known due references before '.$meta['as_of'].'; '.$reviewKnown.' of '.count($rows).' records have a due reference; missing references are not assumed compliant'), $m('overdue', 'Overdue actions', $overdue, 'actions', 'Outstanding known-deadline actions only')];
        if ($role === 'admin') {
            $metrics[1] = $m('checks', 'Records needing checks', $checks, 'people', 'Missing setup or source information');
        }
        if ($role === 'apprentice') {
            $metrics[4] = $m('otj', 'Recorded main-aim OTJ', $otjPeople ? round($otj, 2) : null, 'hours', 'Loaded confirmed register, approved extra and checked historical sources');
            $metrics = [$metrics[1], $metrics[2], $metrics[3], $metrics[4]];
        }
        if (in_array($role, ['employer', 'mentor'])) {
            $pending = count(array_filter($actions, fn ($x) => in_array($x['state'], ['Pending', 'To do', 'Review due reference'], true)));
            $metrics = [$m('people', 'Authorised apprentices', count($rows), 'people', 'Current verified access only'), $m('pending', 'Outstanding shared actions', $pending, 'actions', 'Published requests or assigned review references'), $m('overdue', 'Overdue shared actions', $overdue, 'actions', 'Known deadlines before '.$meta['as_of']), $m('reviews', 'Overdue review references', $reviewDue, 'reviews', 'Due references available for '.$reviewKnown.' of '.count($rows).' records; not a count of unsigned review packets')];
        }
        $weekly = [];
        $start = Carbon::parse($f['from'])->startOfWeek();
        $end = Carbon::parse($f['to']);
        while ($start->lte($end)) {
            $v = [];
            foreach ($rows as $a) {
                foreach ($a['attendance_rows'] as $x) {
                    if ($x['date'] >= max($f['from'], $start->toDateString()) && $x['date'] <= min($f['to'], $start->copy()->endOfWeek()->toDateString())) {
                        $v[] = $x;
                    }
                }
            }$r = AttendanceMath::report($v);
            $weekly[] = ['label' => $start->format('d M'), 'value' => $r['percent'] === null ? null : round($r['percent'], 1), 'attended' => $r['attended'], 'expected' => $r['expected'], 'unmarked' => $r['unmarked']];
            $start->addWeek();
        }
        $charts = [];
        if ($validPeople) {
            $charts[] = ['key' => 'task-status', 'title' => 'Task decisions', 'type' => 'stacked', 'unit' => 'tasks', 'note' => 'Current allocated records. Awaiting marking belongs to the tutor; source checks are separate.', 'rows' => array_map(fn ($k, $v) => ['label' => $k, 'value' => $v], array_keys($status), array_values($status))];
        }
        if ($expected) {
            $charts[] = ['key' => 'attendance', 'title' => 'Weekly attendance', 'type' => 'columns', 'unit' => '%', 'note' => 'Attendance period only. Missing marks break the series; no attendance RAG threshold is invented.', 'rows' => $weekly];
        }
        if ($validPeople && $plannedKnown && in_array($role, ['admin', 'manager', 'tutor'], true)) {
            $charts[] = ['key' => 'deadline-comparison', 'title' => 'Approved tasks and due-task reference', 'type' => 'bars', 'unit' => 'tasks', 'note' => 'Current known-source allocation for '.$validPeople.' apprentices. Saved task due dates are not tutor marking deadlines; work awaiting marking may explain a gap. This does not establish overall on-track status.', 'rows' => [['label' => 'Approved known-source tasks', 'value' => $approved], ['label' => 'Tasks due by saved deadlines', 'value' => $planned]]];
        }
        if ($meta['task_history'] && $validPeople) {
            $points = [];
            for ($i = 0; $i < 6; $i++) {
                $points[] = ['label' => Carbon::parse($meta['as_of'])->subWeeks(5 - $i)->format('d M'), 'value' => (int) floor($approved * ($i + 1) / 6), 'planned' => $plannedKnown ? (int) floor($planned * ($i + 1) / 6) : null];
            }$charts[] = ['key' => 'task-history', 'title' => 'Approved tasks against recorded task plan', 'type' => 'line', 'unit' => 'tasks', 'note' => 'Dated fictional snapshots for this fixed demonstration cohort. The last point reconciles to the current task totals. Live history is not reconstructed.', 'rows' => $points];
        }
        if (in_array($role, ['admin', 'manager', 'tutor']) && $validPeople) {
            $states = [];
            foreach ($rows as $a) {
                $key = $a['outcome'];
                $states[$key] = ($states[$key] ?? 0) + 1;
            }if (count(array_filter(array_keys($states), fn ($v) => ! in_array($v, ['Not recorded', 'Not available', 'Not shared'], true)))) {
                $charts[] = ['key' => 'programme-status', 'title' => 'Current recorded programme status', 'type' => 'bars', 'unit' => 'apprentices', 'note' => 'Current recorded outcomes for this filtered cohort. Counts are not an official retention or achievement rate. Unknown status remains separate.', 'rows' => array_map(fn ($k, $v) => ['label' => $k, 'value' => $v, 'cohort' => count($rows), 'tasks' => null], array_keys($states), array_values($states))];
            }
        }
        if (count($rows) === 1) {
            $person = $rows[0];
            $otjRows = [];
            for ($i = 0; $i < 6; $i++) {
                $date = Carbon::parse($meta['as_of'])->subWeeks(5 - $i)->toDateString();
                if ($demo) {
                    $actual = $person['otj']['recorded_hours'] === null ? null : round($person['otj']['recorded_hours'] * ($i + 1) / 6, 2);
                    $due = $person['otj']['planned_due_hours'] === null ? null : round($person['otj']['planned_due_hours'] * ($i + 1) / 6, 2);
                } else {
                    $ledger = $person['otj_ledger'] ?? [];
                    $actual = count($ledger) ? round(array_sum(array_column(array_filter($ledger, fn ($x) => $x['date'] <= $date), 'minutes')) / 60, 2) : null;
                    $plan = $person['otj_plan'] ?? [];
                    $due = count($plan) ? array_sum(array_column(array_filter($plan, fn ($x) => $x['number'] < 12 && $x['end'] < $date), 'hours')) : null;
                }$otjRows[] = ['label' => $date, 'value' => $actual, 'planned' => $due];
            }if ($person['otj']['recorded_hours'] !== null || $person['otj']['planned_due_hours'] !== null) {
                $charts[] = ['key' => 'otj-history', 'title' => 'Recorded OTJ and current approved plan', 'type' => 'line', 'unit' => 'hours', 'note' => $demo ? 'Dated fictional OTJ snapshots, not task progress.' : 'Cumulative loaded confirmed/approved sources and block ends from the current approved plan, not historical plan versions. Pending sources can make the recorded subtotal incomplete.', 'rows' => $otjRows];
            }
        }
        $groups = [];
        foreach ($rows as $a) {
            $key = $a['programme'];
            $groups[$key] ??= ['label' => $key, 'value' => 0, 'cohort' => 0, 'tasks' => 0];
            $groups[$key]['cohort']++;
            if ($a['tasks'] !== null) {
                $groups[$key]['value'] += $a['approved'];
                $groups[$key]['tasks'] += $a['tasks'];
            }
        }
        if ($validPeople && count($rows) > 1 && ! in_array($role, ['employer', 'mentor'])) {
            $charts[] = ['key' => 'groups', 'title' => 'Approved tasks by programme', 'type' => 'bars', 'unit' => 'tasks', 'note' => 'Counts, not staff rankings. Cohort sizes and allocated-task counts are shown; mixed programme duration is not normalised.', 'rows' => array_values($groups)];
        }
        foreach ($rows as &$row) {
            unset($row['otj_ledger'],$row['otj_plan']);
        }unset($row);
        usort($actions, function ($a, $b) use ($meta) {
            $rank = fn ($x) => $x['overdue'] ? 0 : ($x['due'] === $meta['as_of'] ? 1 : ($x['due'] === null ? 2 : 3));

            return [$rank($a), $a['due'] ?? '', $a['name']] <=> [$rank($b), $b['due'] ?? '', $b['name']];
        });
        $actionList = array_values(array_filter($actions, fn ($x) => ! in_array($x['state'], ['Responded', 'Confirmed', 'Completed'], true) && ($f['metric'] !== 'overdue' || $x['overdue'])));
        $detail = array_values(array_filter($rows, fn ($a) => match ($f['metric']) {
            'approved' => ($a['approved'] ?? 0) > 0,'attendance' => $a['attendance']['expected'] > 0,'otj' => $a['otj']['recorded_hours'] !== null,'checks' => count($a['checks']) > 0,'awaiting' => ($a['task_status']['Awaiting marking'] ?? 0) > 0,'reviews' => $a['review_due'] && $a['review_due'] < $meta['as_of'],'overdue' => count(array_filter($actions, fn ($x) => $x['person_id'] === $a['id'] && $x['overdue'])) > 0,'pending' => count(array_filter($actions, fn ($x) => $x['person_id'] === $a['id'] && in_array($x['state'], ['Pending', 'To do', 'Review due reference'], true))) > 0,'responded' => count(array_filter($actions, fn ($x) => $x['person_id'] === $a['id'] && in_array($x['state'], ['Responded', 'Confirmed'], true))) > 0,default => true
        }));
        usort($detail, function ($a, $b) use ($f) {
            $key = match ($f['sort']) {
                'due' => 'review_due','approved' => 'approved',default => 'name'
            };
            $v = ($a[$key] ?? 'zzzz') <=> ($b[$key] ?? 'zzzz');

            return $f['direction'] === 'desc' ? -$v : $v;
        });
        $selected = $role === 'apprentice' ? ($rows[0] ?? null) : (count($rows) === 1 ? $rows[0] : null);

        return ['role' => $role, 'demo' => $demo, 'banner' => $demo ? 'Demonstration — fictional data' : '', 'captured_at' => now()->toIso8601String(), 'source_as_of' => $meta['as_of'], 'notice' => $meta['notice'], 'period' => $f['from'].' to '.$f['to'], 'filters' => $f, 'groups' => $groupOptions, 'programmes' => $programmes, 'metrics' => $metrics, 'charts' => $charts, 'rows' => $rows, 'details' => $detail, 'actions' => $actionList, 'selected' => $selected, 'record_count' => count($rows), 'known_task_people' => $validPeople, 'totals' => ['allocated' => $validPeople ? array_sum($status) : null, 'approved' => $validPeople ? $approved : null, 'expected_tasks' => $validPeople && $plannedKnown ? $planned : null, 'attendance_attended' => $attended, 'attendance_expected' => $expected, 'attendance_unmarked' => $unmarked], 'task_history_available' => $meta['task_history'], 'definitions' => self::definitions(), 'limited' => $meta['limited'] ?? false];
    }

    public static function groups(User $u): array
    {
        if (! in_array($u->role,['admin', 'ceo', 'tutor', 'apprentice'],true)) {
            return [];
        }

return TutorGroup::whereIn('id',Apprentice::visibleTo($u)->select('tutor_group_id')->whereNotNull('tutor_group_id'))->orderBy('name')->pluck('name','id')->all();
    }

    public static function definitions(): array
    {
        return [
            'Task progress = approved known-source tasks ÷ all allocated known-source tasks. Source-pending records are counted separately. This does not award KSBs, Gateway, EPA or an overall apprenticeship percentage.',
            'Expected task progress = known-source tasks whose saved deadlines are on/before today ÷ allocated known-source tasks. Withheld if deadlines are missing. This is a due-task reference, not an approved approval target, OTJ plan or linear time-elapsed assumption. Submitted work awaiting tutor decisions can explain an approval gap; no apprentice lateness or overall on-track judgement is inferred.',
            'Attendance: O/T attended; A/H/S/W missed; C/B/E excluded; cancelled sessions excluded. Attended ÷ (attended + missed + unmarked). Any unknown/unmarked record or no expected sessions withholds the percentage. Two complete consecutive missed weeks use the existing AttendanceMath rule.',
            'Recorded OTJ: confirmed eligible registers + approved occurred extra training + checked historical ledger. Main-aim target and due-to-date hours come only from the current approved delivery plan; due hours use blocks already ended. Delivered hours are unavailable without a separate reliable measure. Approved-extra hours are a subset, not the full recorded total. Pending and invalid sources remain visible.',
            'English/maths positive-hour assigned topics: completed topics ÷ assigned topics. Qualification status remains separate; Functional Skills hours are never added to main-aim OTJ.',
            'Review overdue means a known cycle due reference before the source-as-of date. A due reference is not a booking, signature, compliance percentage or proof that a review was missed. Unknown dates remain visible in detailed records.',
            'Deadline words: Overdue = known deadline before the as-of date and unfinished; Due today = same date; Upcoming = later date; Not recorded = no valid deadline. No arbitrary attendance red/amber threshold is adopted.',
            'Forecasts are checked staff estimates in the existing forecast workflow; this dashboard does not extrapolate from task pace. Current data is captured at the displayed time; only attendance uses the attendance period.',
        ];
    }
}

@extends('layout')
@section('title','Retention and early-leaver report')
@section('content')
@if(\App\Support\LeaverDestinations::allowed(auth()->user()))<p><a href="{{ route('destinations.index') }}">Leaver follow-up and destination checks</a></p>@endif
<header class="page-heading"><div><h1>Retention and early-leaver report</h1><p>Compare recorded outcomes and context. Identify themes to investigate.</p></div><button type="button" data-print>Print / Save PDF</button></header>
<section class="panel">
    <h2>Cohort outcomes</h2>
    <p>First day in learning · {{ request('from') ?: 'Any start date' }} to {{ request('to') ?: 'Latest recorded starts' }}. {{ $cohortCount }} apprentices in scope.</p>
    <div class="priority-summary">
        @foreach(['achievers' => 'Achievers', 'early-leavers' => 'Early leavers', 'withdrawals' => 'Withdrawals', 'active' => 'Confirmed active', 'checks' => 'Records to check'] as $key => $label)
            <a class="button" href="{{ route('retention.report', array_replace(request()->except('page','export'), ['outcome' => $key])) }}#retention-source-records">
                <strong>{{ $headlines[$key] }}</strong> {{ $label }}
            </a>
        @endforeach
    </div>
    <p>Early leavers are included in withdrawals. Use <a href="{{ route('achievers-leavers.index') }}">Achievers and Early Leavers</a> to report by outcome date.</p>
</section>
@include('learning.errors')
<p><a href="{{ route('achievers-leavers.index') }}">Achievers and Early Leavers</a> · <a href="{{ route('retention.admin') }}">Data-quality actions</a> · <a href="{{ route('retention.admin',['show'=>'followup']) }}">3, 6 and 12-month follow-up</a> · <a href="{{ route('retention.report',array_merge(request()->except('page','outcome'),['export'=>'csv'])) }}">Export aggregate comparison CSV</a></p>
<p class="test-notice">Internal descriptive analysis, not official QAR, a risk prediction or evidence that a factor caused someone to leave. Completion is separate from achievement. Unknown data stays visible.</p>
<form class="panel search" method="get"><div><label for="cohort-from">First-learning-day cohort from</label><input id="cohort-from" name="from" type="date" value="{{ request('from') }}"></div><div><label for="cohort-to">Cohort to</label><input id="cohort-to" name="to" type="date" value="{{ request('to') }}"></div><div><label for="cohort-factor">Compare by</label><select id="cohort-factor" name="factor">@foreach(['programme'=>'Apprenticeship','entry_employer'=>'Employer at entry','linked_employer'=>'Employer linked to the profile','age'=>'Age band at start','size'=>'Employer headcount band','maths'=>'Functional Skills Maths required at entry','english'=>'Functional Skills English required at entry','additional'=>'Additional needs/support context','ehcp'=>'EHC plan context','reason'=>'Recorded leaving reason'] as $key=>$label)<option value="{{ $key }}" @selected($factor===$key)>{{ $label }}</option>@endforeach
@foreach($entryFactors as $key)<option value="{{ $key }}" @selected($factor===$key)>{{ ucwords(str_replace('_',' ',$key)) }} at entry</option>@endforeach</select></div>
<div><label for="standard-filter">Compare within programme</label><select id="standard-filter" name="programme_id"><option value="">All programmes</option>@foreach($programmes as $p)<option value="{{ $p->id }}" @selected((string)request('programme_id')===(string)$p->id)>{{ $p->name }}</option>@endforeach</select></div>
@foreach(['entry_employer_id' => 'Employer at entry', 'linked_employer_id' => 'Employer linked to the profile'] as $field => $label)
<div>
    <label for="{{ $field }}">{{ $label }}</label>
    <select id="{{ $field }}" name="{{ $field }}">
        <option value="">All employers</option>
        @foreach($employerLabels as $id => $name)
            <option value="{{ $id }}" @selected((string) request($field) === (string) $id)>{{ $name }}</option>
        @endforeach
    </select>
</div>
@endforeach
<div><label for="age-filter">Age band at entry</label><select id="age-filter" name="age"><option value="">All age bands</option>@foreach($ageChoices as $age)<option @selected(request('age')===$age)>{{ $age }}</option>@endforeach</select></div>
<div><label for="prior-filter">Already employed at entry</label><select id="prior-filter" name="prior"><option value="">All circumstances</option>@foreach(['Yes','No','Unknown','Prefer not to say'] as $v)<option @selected(request('prior')===$v)>{{ $v }}</option>@endforeach</select></div><button>Apply cohort</button></form>
<p>{{ $cohortCount }} records included · {{ $excluded }} excluded by selected filters or missing filter data. Complete historical employer/tutor counts: Unknown — full history not connected. Recorded current-portal links can be counted separately below. Concern days stop at exit for ended programmes, or today for ongoing apprentices. They cover recorded acknowledged intervals only; overlapping cases count each calendar day once.</p>
@include('retention.early-warning')
<section class="panel"><h2>Recorded cohort comparison</h2><p>Use programme, age, entry employer, prior employment and intake dates together to compare similar circumstances. This is descriptive stratification, not evidence of causation or a statistically adjusted effect. Family relationships never automatically become concerns. Cells below 10 are exploratory; sharing small cells externally requires separate disclosure review.</p><div class="table-scroll"><table><thead><tr><th>Comparison group</th><th>Included</th><th>Withdrawn / completed or withdrawn</th><th>Early leavers</th><th>Achieved / completed with known result</th><th>Completed without achievement</th><th>Completed results missing</th><th>Unknown status</th><th>Transferred</th><th>Median leaving days / known dates</th></tr></thead><tbody>
@foreach($groups as $label=>$g)<tr><td>
@if($byEmployer && $employerIdsByLabel->has($label))
    @php($filter = $factor === 'entry_employer' ? 'entry_employer_id' : 'linked_employer_id')
    <a href="{{ route('retention.report', array_replace(request()->except('page'), ['factor' => $factor, $filter => $employerIdsByLabel->get($label)])) }}#retention-source-records">{{ $label }}</a>
@else
    {{ $label }}
@endif
@if($g['small'] || ($byEmployer && $g['known_results'] < 10)) · Small sample: exploratory @endif
</td><td>{{ $g['total'] }}</td><td>{{ $g['withdrawn'] }} / {{ $g['terminal'] }} · {{ $g['withdrawal_rate']===null?'N/A':$g['withdrawal_rate'].'%' }}</td><td>{{ $g['early_leavers'] }}</td><td>{{ $g['achieved'] }} / {{ $g['known_results'] }} · {{ $g['achievement_rate']===null?'N/A':$g['achievement_rate'].'%' }}</td><td>{{ $g['not_achieved'] }}</td><td>{{ $g['missing_results'] }}</td><td>{{ $g['unknown'] }}</td><td>{{ $g['transferred'] }}</td><td>{{ $g['median_leaving_days']??'Unknown' }} / {{ $g['leaving_dates_known'] }}</td></tr>@endforeach</tbody></table></div><p>Active and break-in-learning records are included in cohort counts, excluded from closed-outcome denominators. Transfers are shown separately. Early leavers are withdrawals before the recorded planned end date. Achievement rate covers known completed results only; missing results must be considered alongside it. This is not official QAR. Calendar days before leaving exclude the exit day.</p>
@if($byEmployer)<p>Employer at entry uses the recruitment record. Employer linked to the profile uses the current profile link. Select an employer name to review its apprentices, leaving reasons, attendance and support records.</p>@endif
</section>
<section class="panel">
    <h2>Register attendance by programme outcome</h2>
    <p>Uses saved registers in each apprenticeship's recorded date range, through the actual programme end date or today. The average uses apprentices with a calculable rate. If any expected session is unmarked, the apprentice's rate is withheld.</p>
    <div class="table-scroll"><table>
        <thead><tr><th scope="col">Programme outcome</th><th scope="col">Apprentices with a rate</th><th scope="col">Average attendance</th><th scope="col">Sessions attended</th><th scope="col">Sessions missed</th><th scope="col">Unmarked sessions</th></tr></thead>
        <tbody>
        @forelse($attendanceByOutcome as $outcome => $register)
            <tr>
                <th scope="row">{{ $outcome }}</th>
                <td>{{ $register['known'] }} / {{ $register['apprentices'] }}</td>
                <td>{{ $register['mean'] === null ? 'Unavailable' : $register['mean'].'%' }}</td>
                <td>{{ $register['attended'] }}</td><td>{{ $register['missed'] }}</td><td>{{ $register['unmarked'] }}</td>
            </tr>
        @empty
            <tr><td colspan="6">No apprentices in the selected cohort.</td></tr>
        @endforelse
        </tbody>
    </table></div>
</section>
<section class="panel">
    <h2>Maths and English training starts</h2>
    <p>Counts cover apprentices recorded as requiring the subject at entry. Start dates come from Functional Skills qualification records up to the programme end date, or through today for ongoing apprentices.</p>
    <div class="table-scroll"><table>
        <thead><tr><th scope="col">Subject</th><th scope="col">Required at entry</th><th scope="col">Start recorded</th><th scope="col">No start recorded</th><th scope="col">Median days to start</th><th scope="col">Early leavers with no start recorded</th></tr></thead>
        <tbody>
        @foreach($skillsStarts as $subject => $starts)
            <tr>
                <th scope="row">{{ ucfirst($subject) }}</th>
                <td>{{ $starts['required'] }}</td><td>{{ $starts['started'] }}</td><td>{{ $starts['no_start'] }}</td>
                <td>{{ $starts['median_days'] === null ? 'Unavailable' : $starts['median_days'].' days · '.$starts['timed_starts'].' with both dates' }}</td>
                <td>{{ $starts['early_leavers_without_start'] }}</td>
            </tr>
        @endforeach
        </tbody>
    </table></div>
</section>
<section class="panel">
    <h2>Apprentice marker trends</h2>
    <p>Shows markers recorded up to the programme end date, or today for ongoing apprentices. Earlier history may be incomplete. An apprentice can appear under several markers. The early-leaver share uses recorded completions and withdrawals with enough date information to classify them. Groups with fewer than 10 outcomes are exploratory.</p>
    <div class="table-scroll"><table>
        <thead><tr><th scope="col">Marker</th><th scope="col">Apprentices with marker</th><th scope="col">Recorded periods</th><th scope="col">Total recorded days</th><th scope="col">Change records</th><th scope="col">Early leavers / known outcomes</th></tr></thead>
        <tbody>
        @forelse($markerTrends as $code => $trend)
            <tr><td>{{ $code }} · {{ $trend['label'] }}</td><td>{{ $trend['people'] }}</td><td>{{ $trend['occasions'] }}</td><td>{{ $trend['days'] }}</td><td>{{ $trend['records'] }}</td><td>{{ $trend['early_leavers'] }} / {{ $trend['known_outcomes'] }}
                @if($trend['known_outcomes'])
                    · {{ \App\Support\RetentionAnalysis::rate($trend['early_leavers'], $trend['known_outcomes']) }}%
                    @if($trend['known_outcomes'] < 10) · Small sample @endif
                @endif
                </td></tr>
        @empty
            <tr><td colspan="6">No recorded apprentice markers in this cohort.</td></tr>
        @endforelse
        </tbody>
    </table></div>
</section>
<section class="panel"><h2>Destinations and follow-up coverage</h2><p>{{ $unscheduled }} sourced exits have no current milestone set; reconcile follow-up tasks before treating coverage as complete. A destination with a later reference date is not relabelled as an exit destination; it remains available in the original outcome record. Each due milestone denominator includes those not reached. A declined or no-reply attempt does not establish destination. Continuity from exit is a separate sourced claim, not inferred from repeated snapshots.</p>
@foreach($groups as $label=>$g)<h3>{{ $label }}</h3><p>Recorded attendance: mean {{ $g['attendance_mean']??'Unknown' }}%, {{ $g['attendance_known'] }} / {{ $g['total'] }} apprentices with a calculable register rate (unweighted mean). Current task approvals: {{ $g['tasks_approved'] }} / {{ $g['tasks_assigned'] }} tasks; {{ $g['task_records'] }} / {{ $g['total'] }} apprentices have assigned tasks. Delivered support recorded for {{ $g['support_delivered_people'] }} / {{ $g['total'] }} apprentices; mean recorded support days {{ $g['support_days_mean']??'Unknown' }} across {{ $g['support_days_known'] }} / {{ $g['total'] }} with dated exposure.</p><p>Withdrawal reason and concern combinations: @foreach($g['reason_concerns'] as $reason=>$count){{ $reason }}: {{ $count }} / {{ $g['withdrawn'] }} withdrawn; @endforeach</p><p>Destination recorded for the actual exit date: @foreach($g['exit_destinations'] as $destination=>$count){{ $destination }}: {{ $count }} / {{ $g['total'] }}; @endforeach</p><p>Recorded concern combinations (not causal attribution): @foreach($g['concern_combinations'] as $codes=>$count){{ $codes }}: {{ $count }} / {{ $g['total'] }}; @endforeach</p>
@foreach($milestoneGroups[$label] as $month=>$m)<p>{{ $month }} months: {{ $m['eligible'] }} scheduled; {{ $m['due'] }} due; {{ $m['checked'] }} have attempts; destination known {{ $m['known'] }} / {{ $m['due'] }} ({{ \App\Support\RetentionAnalysis::rate($m['known'],$m['due'])??'N/A' }}%); missing/unknown {{ $m['unknown'] }}. Uninterrupted employment/training confirmed from exit: {{ $m['uninterrupted_from_exit'] }} / {{ $m['due'] }} ({{ $m['verified_uninterrupted'] }} verified; remainder reported).</p>@endforeach
@endforeach</section>
<section class="panel" id="retention-source-records"><h2>Source records and gaps</h2>
@if($selectedOutcome)
<p>{{ ucfirst(str_replace('-', ' ', $selectedOutcome)) }} · {{ $rows->total() }} records · <a href="{{ route('retention.report', request()->except('outcome','page','export')) }}#retention-source-records">Show all records</a></p>
@endif
@forelse($rows as $row)<article class="work-row"><div><h3>{{ $row['apprentice']->first_name }} {{ $row['apprentice']->last_name }}</h3><p>{{ $byEmployer ? $row['sourced_status'] : $row['status'] }} · Achievement {{ $byEmployer ? $row['sourced_achievement'] : $row['achievement'] }}</p>
<p>Employer at entry · {{ $row['entry_employer'] ?? 'Not recorded' }}<br>
Employer linked to the profile · {{ $row['linked_employer'] ?? 'Not recorded' }}</p>
<p>Functional Skills required at entry · Maths {{ $row['maths_requirement'] }} · English {{ $row['english_requirement'] }}</p>
@foreach($row['functional_skills'] as $subject => $training)
    @if($training['required'] === 'Yes' || $training['start'])
        <p>{{ ucfirst($subject) }} training start · {{ $training['start'] ?? 'No start recorded' }}
        @if($training['days'] !== null) · {{ $training['days'] }} days from apprenticeship start @endif
        </p>
    @endif
@endforeach
@if($row['achievement_date'])
<p>Achievement date {{ $row['achievement_date'] }} · Grade {{ $row['epa_grade'] ?? 'Not recorded' }} · EPA attempt {{ $row['epa_attempt'] ?? 'Not recorded' }}
@if($row['epa_attempt'])
    · <a href="{{ route('apprentices.close-down', $row['apprentice']) }}">View archived completion</a>
@endif
</p>
@endif
@if($row['exit_employment'])
<p>Employment at exit · {{ $row['exit_employment'] }}</p>
@endif
@if($row['marker_summary']['markers'] || $row['marker_summary']['records'])
    @php($markerSummary = $row['marker_summary'])
    <details>
        <summary>{{ $markerSummary['retention_count'] }} distinct retention markers · {{ $markerSummary['retention_days'] }} days with a retention marker · {{ $markerSummary['retention_occasions'] }} periods</summary>
        <p>{{ count($markerSummary['markers']) }} distinct markers overall through {{ $markerSummary['as_of'] }} · {{ $markerSummary['records'] }} marker change records, including {{ $markerSummary['retention_records'] }} that changed a retention marker.</p>
        <p>Days with any retention marker count overlaps once. A new period begins when a retention marker is recorded after all retention markers have cleared.</p>
        @if($markerSummary['incomplete'])
            <p>Some retention markers were already present at the first recorded change. Days are counted from the earliest dated evidence available.</p>
        @endif
        <ul>
            @foreach($markerSummary['markers'] as $code => $marker)
                <li>{{ $code }} · {{ $markerLabels[$code] ?? $code }} · {{ $marker['occasions'] }} recorded periods · {{ $marker['days'] }} days</li>
            @endforeach
        </ul>
    </details>
@else
    <p>No apprentice markers recorded through this date.</p>
@endif
@if($row['withdrawal_timing'])
<p>{{ $row['withdrawal_timing'] }} · Withdrawal date {{ $row['end_date'] ?? 'Needs checking' }} · Planned end {{ $row['planned_end'] ?? 'Needs checking' }}</p>
@endif
<p>Entry data checks: {{ count($row['entry_gaps']) }} · <a href="{{ route('retention.entry',$row['apprentice']) }}">Check recruitment and entry</a></p><p>Approved learning tasks {{ $row['approved_tasks'] }} / {{ $row['assigned_tasks'] }} assigned. This is task completion, not a qualification result or progress against planned dates.</p><p>Support: {{ $row['support_plans'] }} recorded plans; {{ $row['support_approved'] }} with a recorded approval; {{ $row['support_activities'] }} actual activity records. Recorded support exposure: {{ $row['support_exposure']['days']??'Unknown' }} calendar days across {{ $row['support_exposure']['intervals'] }} dated intervals; {{ $row['support_exposure']['unknown'] }} plans have incomplete dates. Plans alone do not establish that support was delivered. <a href="{{ route('support.index',$row['apprentice']) }}">Check dated support evidence</a></p><p>Leaving reason: {{ $row['reason'] }} · {{ $row['reason_basis'] }}</p><p>Employer count: {{ $row['employers'] }} · Tutor count: {{ $row['tutors'] }}</p><p>
    @php($register = $row['recorded_attendance'])
    @if($register['through'])
        Register attendance through {{ $register['through'] }} · {{ $register['percent'] === null ? 'Rate unavailable' : round($register['percent'], 1).'%' }}
        · {{ $register['attended'] }} attended · {{ $register['missed'] }} missed · {{ $register['unmarked'] }} unmarked
        · <a href="{{ route('attendance.index', ['apprentice' => $row['apprentice']->id]) }}">View apprentice registers</a>
    @else
        Register attendance unavailable until the programme end date and source are confirmed.
    @endif
</p><p>{{ $row['concern_cases'] }} concern records present by the exit date, or now for ongoing apprentices (all codes); recorded acknowledged concern days: {{ $row['concern_duration']['days']??'Unknown' }}. This is not a lifetime concern count or complete historical duration.</p><p>Valid intervals: {{ $row['concern_duration']['recorded_intervals'] }} · Awaiting acknowledgement: {{ $row['concern_duration']['unacknowledged'] }} · Dates to check: {{ $row['concern_duration']['date_checks'] }}. Calendar days include the first and last day, through {{ $row['concern_duration']['as_of'] }} for open cases.</p></div><a class="button" href="{{ route('retention.edit',$row['apprentice']) }}">Check outcome and context</a></article>@empty
<p>No records in this cohort.</p>
@endforelse
{{ $rows->withQueryString()->fragment('retention-source-records')->links() }}
</section>
@endsection

@php($canPlan = \App\Support\CentrePlanning::allowed(auth()->user()))
<nav class="toolbar" aria-label="Timetable navigation">
    <a class="button" href="{{ route('centre-planning.index') }}">Weekly timetable</a>
    <a class="button" href="{{ route('centre-planning.index', ['scope' => 'own']) }}">My timetable</a>
    @if($canPlan)
        <a class="button" href="{{ route('centre-planning.form') }}">Plan an activity</a>
        <a class="button" href="{{ route('timetable-duties.covers') }}">Teaching cover</a>
    @endif
</nav>
<details class="panel action-disclosure64">
    <summary>More timetable tools</summary>
    <ul>
        <li><a href="{{ route('centre-planning.index', ['scope' => 'staff']) }}">Staff timetable</a></li>
        @if($canPlan)
            <li><a href="{{ route('centre-planning.plans') }}">Drafts and published series</a></li>
            <li><a href="{{ route('timetable-duties.flexible') }}">Flexible Duties Days</a></li>
            <li><a href="{{ route('centre-planning.windows') }}">Working time</a></li>
            <li><a href="{{ route('timetable-duties.groups') }}">Group details</a></li>
            <li><a href="{{ route('centre-planning.setup') }}">Rooms and planning access</a></li>
            <li><a href="{{ route('annual.index') }}">Plan the full year</a></li>
            <li><a href="{{ route('optimiser.index') }}">Improve the timetable</a></li>
        @endif
        @if(\App\Support\TimetableGovernance::manager(auth()->user()))
            <li><a href="{{ route('timetable-governance.index') }}">Roles and CEO control</a></li>
            <li><a href="{{ route('timetable-management.index') }}">Workload and register measures</a></li>
        @endif
    </ul>
</details>

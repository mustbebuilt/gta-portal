@extends('layout')
@section('title', 'Training gaps report')
@section('content')
<header class="page-heading">
    <div>
        <p class="eyebrow">Tutor register · Personal training bank</p>
        <h1>Training gaps report</h1>
        <p class="muted">Scoped to your authorised apprentices. Derived from confirmed session delivery across all attended groups.</p>
    </div>
    <a class="button" href="{{ route('attendance.index') }}">Back to sessions</a>
</header>

<section class="panel">
    <form class="search" method="get">
        <div><label for="gaps-search">Apprentice name or reference</label><input id="gaps-search" name="search" value="{{ $search }}" maxlength="100"></div>
        <div><label for="gaps-group">Tutor group</label><select id="gaps-group" name="group"><option value="">All authorised groups</option>@foreach($groups as $g)<option value="{{ $g->id }}" @selected($groupId === $g->id)>{{ $g->name }}</option>@endforeach</select></div>
        <button class="primary">Apply</button>
        @if($search !== '' || $groupId)<a class="button" href="{{ route('training-gaps72.index') }}">Clear</a>@endif
    </form>
    <p class="muted">{{ $totals['apprentices'] }} apprentice(s) in scope · <strong>{{ $totals['with_overdue'] }}</strong> with overdue subjects ({{ $totals['total_overdue'] }} overdue total) · {{ $totals['with_legacy'] }} with legacy unmapped sessions.</p>
    <div class="table-scroll">
        <table>
            <thead><tr><th>Apprentice</th><th>Group</th><th>Delivered</th><th>Overdue</th><th>Part delivered</th><th>Legacy sessions</th><th>Journey</th></tr></thead>
            <tbody>
            @forelse($rows as $row)
                <tr>
                    <td><strong>{{ $row['apprentice']->first_name }} {{ $row['apprentice']->last_name }}</strong><br><small>{{ $row['apprentice']->reference }}</small></td>
                    <td>{{ $row['apprentice']->groupLabel() ?? 'Not assigned' }}</td>
                    <td>{{ $row['counts']['delivered'] }}</td>
                    <td>{{ $row['counts']['overdue'] }}</td>
                    <td>{{ $row['counts']['partial'] }}</td>
                    <td>{{ $row['legacy_count'] }}</td>
                    <td><a class="button" href="{{ route('training-journey72.show', $row['apprentice']) }}">Training journey</a></td>
                </tr>
            @empty
                <tr><td colspan="7">No matching apprentices in your authorised scope.</td></tr>
            @endforelse
            </tbody>
        </table>
    </div>
</section>
@endsection

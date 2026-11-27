@extends('layout')
@section('title', 'Achievers and Early Leavers')
@section('content')
<header class="page-heading"><div><h1>Achievers and Early Leavers</h1>
<p>Recorded outcomes by achievement or withdrawal date.</p></div><a class="button" href="{{ route('retention.report') }}">View retention report</a></header>
@include('learning.errors')
<form method="get" class="panel search">
    <label>From <input type="date" name="from" value="{{ $filters['from'] }}" required></label>
    <label>To <input type="date" name="to" value="{{ $filters['to'] }}" required></label>
    <label>Programme <select name="programme"><option value="">All programmes</option>
    @foreach($programmes as $programme)
        <option value="{{ $programme->id }}" @selected((int) $filters['programme'] === $programme->id)>{{ $programme->name }}</option>
    @endforeach
    </select></label>
    <button>Update report</button>
</form>
@include('reports.headline', [
    'title' => 'Confirmed outcomes',
    'basis' => 'Use the achievement or withdrawal date shown for the selected period.',
    'items' => [
        ["Achievers", $achievers->count()],
        ["Early leavers", $earlyLeavers->count()],
        ["Outcome source checks", $checks->count(), 'exceptions']
    ],
])
<section class="panel"><h2>Achievers</h2>
<div class="table-scroll"><table><thead><tr><th>Apprentice</th><th>Programme</th><th>Achievement date</th><th>Grade</th><th>EPA attempt</th><th>Employment at exit</th></tr></thead><tbody>
@forelse($achievers as $row)
    <tr><td><a href="{{ route('apprentices.close-down', $row['apprentice']) }}">{{ $row['apprentice']->first_name }} {{ $row['apprentice']->last_name }} · {{ $row['apprentice']->reference }}</a></td><td>{{ $row['apprentice']->programmeLabel() ?? 'Not recorded' }}</td><td>{{ $row['date'] }}</td><td>{{ $row['grade'] ?? 'Not recorded' }}</td><td>{{ $row['attempt'] ?? 'Not recorded' }}</td><td>{{ $row['exit_employment'] }}</td></tr>
@empty<tr><td colspan="6">No recorded achievers in this period.</td></tr>@endforelse
</tbody></table></div></section>
<section class="panel"><h2>Early leavers</h2><p>Apprentices who withdrew without completing their programme, before their recorded planned end date.</p>
<div class="table-scroll"><table><thead><tr><th>Apprentice</th><th>Programme</th><th>Withdrawal date</th><th>Recorded planned end</th><th>Recorded reason</th><th>Employment at exit</th></tr></thead><tbody>
@forelse($earlyLeavers as $row)
    <tr><td><a href="{{ route('apprentices.close-down', $row['apprentice']) }}">{{ $row['apprentice']->first_name }} {{ $row['apprentice']->last_name }} · {{ $row['apprentice']->reference }}</a></td><td>{{ $row['apprentice']->programmeLabel() ?? 'Not recorded' }}</td><td>{{ $row['date'] }}</td><td>{{ $row['planned'] ?? 'Not recorded' }}</td><td>{{ $row['reason'] }} · {{ $row['reason_basis'] }}</td><td>{{ $row['exit_employment'] }}</td></tr>
@empty<tr><td colspan="6">No recorded early leavers in this period.</td></tr>@endforelse
</tbody></table></div></section>
@if($checks->isNotEmpty())
<details class="panel"><summary>Outcome source checks · {{ $checks->count() }}</summary>
<p>Check these records before counting them. Undated outcomes appear here across all date ranges.</p>
@foreach($checks as $row)
    <p><a href="{{ route('retention.edit', $row['apprentice']) }}">{{ $row['apprentice']->reference }}</a> · {{ $row['issue'] }} · {{ $row['date'] ?? 'Undated' }}</p>
@endforeach
</details>
@endif
@endsection

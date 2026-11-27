@extends('layout')
@section('title','Kept at work by employer')
@section('content')
<header class="page-heading">
    <h1>Kept at work by employer</h1>
    <a class="button" href="{{ route('retention.report') }}">Retention and early-leaver report</a>
</header>
@include('learning.errors')
<p>Counts come from training registers. Employer attribution uses confirmed entry information and dated employment records. Missing or conflicting dates are listed under Employer needs checking.</p>
<form method="get" class="panel search">
    <div><label for="attendance-from">Session dates from</label><input id="attendance-from" type="date" name="from" value="{{ $from }}" required></div>
    <div><label for="attendance-to">Session dates to</label><input id="attendance-to" type="date" name="to" value="{{ $through }}" required></div>
    <button>Apply dates</button>
</form>
@include('reports.headline', [
    'title' => 'Recorded kept-at-work sessions',
    'basis' => 'Whole reporting period across employers. Selecting an employer narrows the register records below.',
    'items' => [
        ["Missed sessions", $groups->sum('sessions')],
        ["Employer needs checking", $groups->firstWhere('id', 'unknown')['sessions'] ?? 0, 'exceptions']
    ],
])
<section class="panel">
    <h2>Employer totals</h2>
    <div class="table-scroll"><table><thead>
        <tr><th scope="col">Employer</th><th scope="col">Apprentices affected</th><th scope="col">Missed apprentice sessions</th><th scope="col">Most recent</th></tr>
    </thead><tbody>
    @forelse($groups as $group)
        <tr>
            <th scope="row"><a href="{{ route('retention.employer-attendance', ['from' => $from, 'to' => $through, 'employer' => $group['id']]) }}#session-records">
                @if($group['employer']){{ $group['employer']->name }} · {{ $group['employer']->reference }}@else Employer needs checking @endif
            </a></th>
            <td>{{ $group['apprentices'] }}</td><td>{{ $group['sessions'] }}</td><td>{{ $group['latest'] }}</td>
        </tr>
    @empty
        <tr><td colspan="4">No sessions marked Kept at work within these dates.</td></tr>
    @endforelse
    </tbody></table></div>
    <p>One missed group session counts once for each apprentice marked Kept at work.</p>
</section>
<section class="panel" id="session-records">
    <h2>Register records</h2>
    @if($selected)
        <p>{{ $employers->get($selected)?->name ?? 'Employer needs checking' }} · {{ $details->total() }} records.
            <a href="{{ route('retention.employer-attendance', ['from' => $from, 'to' => $through]) }}#session-records">Show all records</a>
        </p>
    @endif
    <div class="table-scroll"><table><thead>
        <tr><th scope="col">Date</th><th scope="col">Apprentice</th><th scope="col">Employer on the date</th><th scope="col">Source</th></tr>
    </thead><tbody>
    @forelse($details as $row)
        <tr>
            <td>{{ $row['date'] }}</td><td>{{ $row['apprentice']->first_name }} {{ $row['apprentice']->last_name }} · {{ $row['apprentice']->reference }}</td>
            <td>{{ $employers->get($row['employer'])?->name ?? 'Employer needs checking' }}</td>
            <td><a href="{{ route('attendance.show', $row['session']) }}">Open register</a></td>
        </tr>
    @empty
        <tr><td colspan="4">No register records to show.</td></tr>
    @endforelse
    </tbody></table></div>
    {{ $details->links() }}
</section>
@endsection

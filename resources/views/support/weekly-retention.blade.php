@extends('layout')
@section('title', 'Weekly early warnings')
@section('content')
<header class="page-heading">
    <h1>Weekly early warnings</h1>
    <a class="button" href="{{ route('support.worklist', ['view' => 'needs-review']) }}">Support and Retention Register</a>
</header>
<p>Monday checks use records through the previous Sunday. Support and Retention reviews the evidence and coordinates any follow-up.</p>
@include('learning.errors')
<form class="panel" method="get">
    <label for="retention-week">Monday check</label>
    <select id="retention-week" name="week">
        @foreach($weeks->prepend($week)->unique() as $date)
            <option value="{{ $date }}" @selected($date === $week)>{{ $date }}</option>
        @endforeach
    </select>
    <button name="view" value="needs-review" @if($view === 'needs-review') aria-current="true" @endif>Needs review</button>
    <button name="view" value="all" @if($view === 'all') aria-current="true" @endif>All</button>
</form>
@forelse($checks as $check)
    <section class="panel">
        <h2>{{ $check->apprentice->first_name }} {{ $check->apprentice->last_name }}</h2>
        @include('support.weekly-retention-evidence', ['evidence' => $check->evidence])
        @if($check->evidence['checks'])
            <p><strong>Records need checking</strong></p>
            <ul>@foreach($check->evidence['checks'] as $reason)<li>{{ $reason }}</li>@endforeach</ul>
        @endif
        @if($check->evidence['attendance'] || $check->evidence['otj'])
            <p>Portal reminders {{ $check->reminders->count() }} of 2 · Emails sent {{ $check->reminders->where('email_state', 'sent')->count() }} of 2</p>
        @endif
        <div class="toolbar">
            <a class="button" href="{{ route('support.index', $check->apprentice) }}">Open support record</a>
            @if($check->reviewed_at)
                <span>Reviewed {{ $check->reviewed_at->format('d M Y') }}</span>
            @else
                <form method="post" action="{{ route('weekly-retention.reviewed', $check) }}">
                    @csrf
                    <button type="submit">Mark reviewed</button>
                </form>
            @endif
        </div>
    </section>
@empty
    <p>No checks to show for this week.</p>
@endforelse
{{ $checks->links() }}
@endsection

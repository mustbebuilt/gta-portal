@extends('layout')
@section('title', 'Weekly training reminders')
@section('content')
<h1>Weekly training reminders</h1>
<p>These checks use records through the Sunday before each reminder. Contact the Support and Retention team if you need help or think a record needs updating.</p>
@forelse($reminders as $reminder)
    <section class="panel">
        <h2>{{ $reminder->check->week_of }}
            @if(auth()->user()->role === 'tutor')
                · {{ $reminder->check->apprentice->first_name }} {{ $reminder->check->apprentice->last_name }}
            @endif
        </h2>
        @include('support.weekly-retention-evidence', ['evidence' => $reminder->check->evidence])
    </section>
@empty
    <p>No weekly training reminders yet.</p>
@endforelse
{{ $reminders->links() }}
@endsection

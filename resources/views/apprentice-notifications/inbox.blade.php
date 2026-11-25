@extends('layout')
@section('title','My reminders')
@section('content')
<header class="page-heading">
    <h1>My reminders</h1>
    <a class="button primary" href="{{ route('experience.home') }}">My next step</a>
</header>
@include('learning.errors')
@php([$unread, $read] = $groups->partition(fn ($records) => $records->contains(fn ($delivery) => !$delivery->seen_at)))
@if($unread->isNotEmpty())
    <section aria-labelledby="unread-digests">
        <h2 id="unread-digests">Unread reminders</h2>
        @foreach($unread as $date => $records)@include('apprentice-notifications.digest')@endforeach
    </section>
@endif
@if($read->isNotEmpty())
    <details class="panel" @if($unread->isEmpty()) open @endif><summary>Read reminders · {{ $read->count() }}</summary>
        @foreach($read as $date => $records)@include('apprentice-notifications.digest')@endforeach
    </details>
@endif
@if($groups->isEmpty())<p>No saved reminders in the last 60 days.</p>@endif
<p><a href="{{ route('alerts.index') }}">Change alert preferences</a></p>
@endsection

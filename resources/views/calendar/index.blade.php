@extends('layout')
@section('title','My calendar')
@section('content')
<header class="page-heading"><div><p class="eyebrow">{{ $calendar['reference'] }}</p><h1>My calendar</h1><p>See your training sessions, deadlines and booked reviews.</p></div><a class="button" href="{{ route('calendar.download',['from'=>$calendar['from'],'to'=>$calendar['to']]) }}">Download calendar file</a></header>
@include('learning.errors')
<form method="get" class="search"><label>From <input type="date" name="from" value="{{ $calendar['from'] }}" required></label><label>To <input type="date" name="to" value="{{ $calendar['to'] }}" required></label><button>Show dates</button><a class="button" href="{{ route('calendar.index') }}">Today onwards</a></form>
<p>Choose up to 93 days. Only booked reviews appear as appointments.</p>
@if($calendar['source_checks'])<p>{{ $calendar['source_checks'] }} calendar items need checking before they can be shown as confirmed.</p>@endif
<section class="panel">@forelse($calendar['events'] as $event)<article class="work-row"><div><h2>{{ $event['title'] }}</h2><p>{{ $event['kind'] }} · {{ $event['date'] }}@if($event['start']) · {{ \Carbon\Carbon::parse($event['start'])->timezone('Europe/London')->format('H:i') }}–{{ \Carbon\Carbon::parse($event['end'])->timezone('Europe/London')->format('H:i') }} UK time @else · All-day deadline/reference @endif @if($event['cancelled']) · <strong>Cancelled</strong>@endif</p></div><a class="button" href="{{ $event['url'] }}">Open details</a></article>@empty<h2>No dates in this view</h2><p>Try another date range.</p>@endforelse</section>
<details class="panel">
    <summary>About the calendar download</summary>
    <p>The file uses generic titles and links that require signing in. Your calendar provider may store a copy outside the portal. Download a fresh copy after changes.</p>
</details>
@endsection

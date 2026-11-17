@extends('layout')
@section('title','Retention chronology')
@section('content')<h1>{{ $apprentice->reference }}: chronological history</h1><p><a href="{{ route('retention.entry',$apprentice) }}">Recruitment and entry</a> · <a href="{{ route('destinations.show',$apprentice) }}">Destination checks</a></p><p>Dated records are shown as held in this portal. A created or last-saved date does not establish an earlier real-world start date. Support details remain in the restricted support workspace.</p>
@foreach($events as $event)<p>{{ $event->event_at }} · {{ $event->label }} · Record {{ $event->id }}</p>@endforeach{{ $events->links() }}@endsection

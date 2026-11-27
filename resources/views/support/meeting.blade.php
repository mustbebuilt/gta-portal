@extends('layout')
@section('title','Support and Retention meeting')
@section('content')
<header class="page-heading"><h1>Support and Retention meeting</h1><a class="button" href="{{route('support.worklist',$meetingFilters)}}">Back to register</a></header>
@include('learning.errors')
@if($selected)
<section class="panel">
    <h2>{{ $apprentice->first_name }} {{ $apprentice->last_name }} · {{ $apprentice->reference }}</h2>
    <p>{{ $selected['due']->count() }} {{ \Illuminate\Support\Str::plural('review', $selected['due']->count()) }} due · {{ $selected['unlinked']->count() }} to check · {{ $remaining }} {{ \Illuminate\Support\Str::plural('apprentice', $remaining) }} after this one</p>
</section>
@include('support.marker-reviews', ['inMeeting' => true])
@include('support.workspace-actions')
<p><a href="{{route('support.index',$apprentice)}}">Full support record and history</a></p>
@else<p>This apprentice is no longer in the selected list or is outside your permitted scope. Open the next apprentice or return to the register.</p>@endif
@if($nextMeeting)<p><a class="button primary" href="{{route('support.worklist',[...$meetingFilters,'meeting'=>$nextMeeting['person']->id])}}">Next apprentice · {{$nextMeeting['person']->first_name}} {{$nextMeeting['person']->last_name}}</a></p>@else<p>No further apprentices in this order. Return to the register to check remaining work.</p>@endif
<script src="{{asset('assets/support-workspace.js')}}?v=2" defer></script>
@endsection

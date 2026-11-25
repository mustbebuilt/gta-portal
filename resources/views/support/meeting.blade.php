@extends('layout')
@section('title','Support and Retention meeting')
@section('content')
<header class="page-heading"><h1>Support and Retention meeting</h1><a class="button" href="{{route('support.worklist',request()->only(['view','search','by','marker']))}}">Back to register</a></header>
@include('learning.errors')
@if($selected)
<h2>{{$apprentice->first_name}} {{$apprentice->last_name}} · {{$apprentice->reference}}</h2>
@include('support.marker-reviews', ['inMeeting' => true])
@include('support.workspace-actions')
<p><a href="{{route('support.index',$apprentice)}}">Full support record and history</a></p>
@else<p>This apprentice is no longer in the selected list, or is outside your permitted scope. No review was recorded by navigation.</p>@endif
@if($nextMeeting)<p><a class="button primary" href="{{route('support.worklist',[...request()->only(['view','search','by','marker']),'meeting'=>$nextMeeting['person']->id])}}">Next apprentice · {{$nextMeeting['person']->first_name}} {{$nextMeeting['person']->last_name}}</a></p>@else<p>No further apprentices in this order. Return to the register to check remaining work.</p>@endif
<script src="{{asset('assets/support-workspace.js')}}?v=2" defer></script>
@endsection

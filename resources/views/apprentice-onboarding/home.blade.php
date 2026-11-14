@extends('layout')
@section('title','Getting started')
@section('content')
<h1>Getting started</h1>@include('learning.errors')
@if(!$apprentice)<section class="panel"><h2>Your profile needs linking</h2><p>Ask GTA to check your account and link your own apprentice profile.</p></section>@else
<p>Work through these six simple checks in your own time. Ask GTA if anything is unclear. This checklist helps you use the portal; your tutor will arrange your formal induction separately.</p>
<section class="panel"><h2>My details and contacts</h2><p>{{ $apprentice->first_name }} {{ $apprentice->last_name }} · {{ $apprentice->reference }}</p><p>{{ $apprentice->programmeLabel()??'Programme needs confirming' }} · {{ $apprentice->employerLabel()??'Employer needs confirming' }}</p><p>Main tutor: {{ $apprentice->tutor?->active?$apprentice->tutor->name:'Ask GTA to confirm' }}</p><p>Learning and progress mentor: {{ $apprentice->mentor?->active?$apprentice->mentor->name:'Ask GTA to confirm' }}</p><p>Start: {{ $apprentice->start_date?->format('d M Y')??'Not recorded' }} · Planned end: {{ $apprentice->planned_end_date?->format('d M Y')??'Not recorded' }}</p><p>If something is wrong, <a href="{{ route('experience.index') }}#new-help">ask GTA to correct it</a>. Never share your password or sign a review for someone else.</p></section>
<p>{{ count(array_filter($checks,fn($c)=>$c['confirmed_at']!==null)) }}/{{ count($checks) }} confirmed.</p>
@foreach($checks as $item=>$check)<section class="panel"><h2>{{ $check['label'] }}</h2><p>{{ $check['detail'] }}</p><p><a href="{{ route($check['route']) }}">Open the relevant page</a></p><p>{{ $check['confirmed_at']?'Confirmed '.$check['confirmed_at']:'Not yet confirmed' }}</p><form method="post" action="{{ route('getting-started.check',$item) }}">@csrf<input type="hidden" name="version" value="{{ $check['version'] }}"><input type="hidden" name="confirmed" value="{{ $check['confirmed_at']?'0':'1' }}"><button>{{ $check['confirmed_at']?'I need to check this again':'I have checked and understand this' }}</button></form></section>@endforeach
<p><a class="button primary" href="{{ route('experience.home') }}">Continue to My next step</a> <a href="{{ route('help.index') }}">Help and how to</a></p>
@endif
@endsection

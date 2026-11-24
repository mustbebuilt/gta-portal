@extends('layout')
@section('title','Routine support')
@section('content')
<header class="page-heading"><div><p class="eyebrow">{{ $apprentice->first_name }} {{ $apprentice->last_name }}</p><h1>Routine support</h1><p>Record the help needed and the next action in one place.</p></div><a class="button" href="{{ route('support.index',$apprentice) }}">Back to support</a></header>
@include('learning.errors')
<p class="muted">Private, non-funded support. No diagnosis or marker is inferred. Use the existing assessed support process for funding decisions. Keep safeguarding-specific details in the appropriate restricted process.</p>
@if($plan?->status==='Closed')<section class="panel"><h2>Closed support history</h2><p>{{ $plan->closure_outcome }}</p></section>
@else
@include('support.routine-form')
@endif
@if($plan)<section class="panel"><h2>Current position</h2><p>{{ $plan->status }} · version {{ $plan->version }}</p>@if($plan->active_plan)<p>Active support: {{ $plan->active_plan['fields']['support'] }}</p><p>Next review: {{ $plan->nextReview() }}</p>@endif<a class="button" href="{{ route('support.plan',[$apprentice,$plan]) }}">Delivered support, reviews, evidence and history</a></section>@endif
@endsection

@extends('layout')
@section('title','Routine support')
@section('content')
<header class="page-heading"><div><p class="eyebrow">{{ $apprentice->first_name }} {{ $apprentice->last_name }}</p><h1>Routine support</h1><p>Record the help needed and the next action in one place.</p></div><a class="button" href="{{ route('support.index',$apprentice) }}">Back to support</a></header>
@include('learning.errors')
<p class="muted">Private, non-funded support. No diagnosis or marker is inferred. Use the existing assessed support process for funding decisions. Keep safeguarding-specific details in the appropriate restricted process.</p>
@if($plan?->status==='Closed')<section class="panel"><h2>Closed support history</h2><p>{{ $plan->closure_outcome }}</p></section>
@else
<form class="panel" method="post" action="{{ route('routine-support.save',[$apprentice,$plan]) }}">@csrf
<input type="hidden" name="version" value="{{ $plan?->version??0 }}"><input type="hidden" name="profile_version" value="{{ $apprentice->version }}"><input type="hidden" name="request_id" value="{{ old('request_id',\Illuminate\Support\Str::uuid()) }}">
@php($f=$plan?->draft['fields']??[])
<label for="routine-need">What help is needed?</label><textarea id="routine-need" name="need" required>{{ old('need',$f['barriers']??'') }}</textarea>
<label for="routine-action">What will we do?</label><textarea id="routine-action" name="action" required>{{ old('action',$f['support']??'') }}</textarea>
<div class="form-grid"><div><label for="routine-owner">Responsible person</label><select id="routine-owner" name="owner_id" required><option value="">Choose a person</option>@foreach($owners as $person)<option value="{{ $person->id }}" @selected((string)old('owner_id',$f['routine_owner_id']??auth()->id())===(string)$person->id)>{{ $person->name }}</option>@endforeach</select></div><div><label for="routine-review">Review date</label><input id="routine-review" name="review" type="date" min="{{ today()->toDateString() }}" value="{{ old('review',$f['review']??'') }}" required></div></div>
<details><summary>Apprentice feedback and intended outcome</summary><label for="routine-view">Apprentice’s view, if discussed</label><textarea id="routine-view" name="apprentice_view">{{ old('apprentice_view',$f['view']??'') }}</textarea><label for="routine-measure">How will we know the support helped?</label><textarea id="routine-measure" name="measure">{{ old('measure',$f['routine_measure']??'') }}</textarea></details>
@if($plan?->active_plan)<label for="routine-reason">Reason for changing active support</label><textarea id="routine-reason" name="change_reason">{{ old('change_reason') }}</textarea><p class="muted">The active version stays in use if you save only a draft.</p>@endif
<div class="form-actions"><button class="primary" name="intent" value="activate">Save and activate support</button><button name="intent" value="draft">Save private draft</button></div></form>
@endif
@if($plan)<section class="panel"><h2>Current position</h2><p>{{ $plan->status }} · version {{ $plan->version }}</p>@if($plan->active_plan)<p>Active support: {{ $plan->active_plan['fields']['support'] }}</p><p>Next review: {{ $plan->nextReview() }}</p>@endif<a class="button" href="{{ route('support.plan',[$apprentice,$plan]) }}">Delivered support, reviews, evidence and history</a></section>@endif
@endsection

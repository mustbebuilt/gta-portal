@extends('layout')
@section('title','My next step')
@section('content')
<header class="page-heading"><div><p class="eyebrow">Apprentice home</p><h1>My next step</h1>@if($apprentice)<p>{{ $apprentice->first_name }}, here is what you can work on next.</p>@endif</div></header>
@if(!$apprentice)<section class="panel"><h2>Your profile needs linking</h2><p>Ask GTA to link one apprentice profile to your account. Your work will appear here once that is checked.</p></section>@else
@include('support.weekly-retention-banner')
<section class="panel experience-next"><h2>{{ $position['next']['title']??'Check your next step with your tutor' }}</h2>@if($position['next'])<p>Due: {{ $position['next']['due']?\Carbon\Carbon::parse($position['next']['due'])->format('d/m/Y'):'No agreed deadline recorded' }}</p><a class="button primary" href="{{ $position['next']['url'] }}">{{ $position['next']['label'] }}</a>@else<p>{{ $position['awaiting'] }} task(s) awaiting marking. Paper assessments, unreleased blocks and tasks without loaded source content need your tutor.</p><a class="button primary" href="{{ route('learning.index',$apprentice) }}">Check my training tasks</a>@endif</section>
<nav class="page-actions64" aria-label="My frequent actions"><div class="toolbar"><a class="button" href="{{ route('learning.index',$apprentice) }}">My tasks</a><a class="button" href="{{ route('calendar.index') }}">My calendar</a><a class="button" href="{{ route('experience.index') }}#new-help">I need help</a><a class="button" href="{{ route('electronic-reviews.index',['filter'=>'mine']) }}">Reviews waiting for my sign-off</a></div>
<details class="action-disclosure64"><summary>My progress and account options</summary><ul>
<li><a href="{{ route('visual-reports.index') }}">My report: charts and exact figures</a></li>
<li><a href="{{ route('communications.own') }}">My reminder status</a></li>
<li><a href="{{ route('getting-started.index') }}">Getting started</a></li>
<li><a href="{{ route('workplace-support.index') }}">Review workplace support sharing choices</a></li>
<li><a href="{{ route('apprentice-reminders.index') }}">My reminders</a></li>
<li><a href="{{ route('gateway.show',$apprentice) }}">My Gateway preparation</a></li>
<li><a href="{{ route('journey.show',$apprentice) }}">My achievement timeline</a></li>
<li><a href="{{ route('experience.index',['filter'=>'achievement']) }}#new-achievement">Record a workplace achievement</a></li>
</ul></details></nav>
<div class="dashboard-grid"><section class="panel"><h2>Next training day</h2>@if($position['session'])<p><strong>{{ $position['session']->name }}</strong></p><p>{{ $position['session']->session_date->format('d/m/Y') }} · {{ $position['session']->start_time }}–{{ $position['session']->end_time }}</p><p>From your saved training-session roster. Check with GTA if plans change.</p>@else<p>No upcoming training session is recorded for you. Check your timetable with GTA.</p>@endif</section>
<section class="panel"><h2>Next review</h2>
@include('reviews.next-step')
</section></div>
@include('gateway.summary')
@php($questionnaireOpen=\App\Support\PortalQuestionnaires::queue(auth()->user())->whereIn('state',['Assigned','In progress'])->count())
<section class="panel"><h2>My questionnaires</h2><p>{{ $questionnaireOpen }} open questionnaire(s). You can save a private draft and submit when ready. Voluntary wellbeing forms have no deadline.</p><a class="button" href="{{ route('portal-questionnaires.index') }}">Open my questionnaires</a></section>
<section class="panel"><h2>My outstanding actions</h2>@forelse($position['actions'] as $action)<p><a href="{{ route('actions.index',$apprentice) }}#action-{{ $action->id }}">{{ $action->title }}</a> · {{ $action->deadline()?->format('d/m/Y')??'Date not set' }} @if($action->overdue()) · Overdue @endif</p>@empty<p>No outstanding apprentice actions are recorded.</p>@endforelse</section>
@include('experience.blocks',['blocks'=>$position['blocks']])
@endif
@endsection

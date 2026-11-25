@extends('layout')
@section('title','Dashboard')
@section('content')
@include('support.weekly-retention-banner')
<header class="page-heading"><h1>{{ auth()->user()->role==='apprentice'?'Your dashboard':'Dashboard' }}</h1></header>
@include('setup68.home-actions')

<p class="muted">{{ $metrics['apprentices'] }} apprentices in this view</p>
@php($taskChart=\App\Support\PortalVisuals::tasks($tasks))
@include('dashboard.priorities')
<details class="panel" data-print-expand><summary>Qualification targets and other follow-up</summary><p>{{ $metrics['qualifications_overdue'] }} qualification targets overdue · {{ $metrics['qualification_date_checks'] }} qualification date checks.</p>
@include('dashboard.register-followup')
@include('dashboard.review-followup')
</details>
<details class="panel" id="dashboard-breakdown" data-print-expand><summary>Progress figures and charts</summary>
@include('visuals.task-summary')
@include('visuals.controls')
<div class="visual-grid">@include('visuals.panel',['chart'=>$taskChart])
@include('visuals.panel',['chart'=>\App\Support\PortalVisuals::topics($topics)])</div>

@include('dashboard.task-attention50')
</details>
<details class="panel" data-print-expand><summary>Apprentices in this view</summary>@forelse($apprentices as $a)<article class="work-row"><div><h3>{{ $a->first_name }} {{ $a->last_name }}</h3><p>{{ $a->reference }} · {{ $a->groupLabel()??'Group not assigned' }}</p></div><div class="toolbar"><a class="button" href="{{ route('learning.index',$a) }}">Tasks</a><a class="button" href="{{ route('actions.index',$a) }}">Actions</a><a class="button" href="{{ route('attendance.apprentice',$a) }}">Attendance</a><a class="button" href="{{ route('journey.show',$a) }}">Journey</a></div></article>@empty<p>No apprentices match this scope.</p>@endforelse</details>
<details class="panel"><summary>How these figures and colours work</summary><p>Green identifies recorded approval/completion. Red identifies an overdue source deadline. Grey means no rating or insufficient information. No additional BRAG thresholds have been invented.</p><p>Marking becomes overdue after more than seven calendar days from the current submission. Qualification targets are twelve calendar months after each qualification’s chosen start. Linked actions follow their original task dates.</p><p>{{ $metrics['marking_date_checks'] }} awaiting submissions have missing submission dates; {{ $metrics['qualification_date_checks'] }} qualification records need date checks. Unknown data does not count as achievement or compliance.</p></details>
@endsection
@extends('layout')
@section('title','Dashboard')
@section('content')
@include('support.weekly-retention-banner')
<header class="page-heading"><h1>{{ auth()->user()->role==='apprentice'?'Your dashboard':'Dashboard' }}</h1></header>
@include('setup68.home-actions')
<form class="panel search" method="get"><div><label for="dashboard-group">Group</label><select id="dashboard-group" name="group"><option value="">All visible groups</option>@foreach($groups as $g)<option value="{{ $g->id }}" @selected((string)request('group')===(string)$g->id)>{{ $g->name }}</option>@endforeach</select></div><div><label for="dashboard-apprentice">Apprentice</label><select id="dashboard-apprentice" name="apprentice"><option value="">All in selected group</option>@foreach($visible as $a)<option value="{{ $a->id }}" @selected((string)request('apprentice')===(string)$a->id)>{{ $a->first_name }} {{ $a->last_name }}</option>@endforeach</select></div><button>Apply scope</button><a class="button" href="{{ route('dashboard.index') }}">Clear</a></form>
<p class="muted">{{ $metrics['apprentices'] }} apprentices in this view</p>
@php($taskChart=\App\Support\PortalVisuals::tasks($tasks))
@include('visuals.task-summary')
@include('dashboard.register-followup')
@include('dashboard.review-followup')
@include('dashboard.priorities')
<div class="dashboard-grid">@foreach(['overdue_marking'=>['Overdue marking','red'],'overdue_actions'=>['Overdue actions','red'],'qualifications_overdue'=>['Overdue qualification targets','red']] as $key=>[$label,$colour])<section class="panel"><h2>{{ $label }}</h2><p class="big-number">{{ $metrics[$key] }}</p><span class="status {{ $metrics[$key]>0?$colour:'neutral' }}">{{ $metrics[$key]>0?($colour==='red'?'Overdue':'Awaiting tutor decision'):'No matching records' }}</span></section>@endforeach</div>
<details class="panel" id="dashboard-breakdown" data-print-expand><summary>Charts and task detail</summary>
@include('visuals.controls')
<div class="visual-grid">@include('visuals.panel',['chart'=>$taskChart])
@include('visuals.panel',['chart'=>\App\Support\PortalVisuals::topics($topics)])</div>

@include('dashboard.task-attention50')
</details>
<section class="panel"><h2>Apprentices</h2>@forelse($apprentices as $a)<article class="work-row"><div><h3>{{ $a->first_name }} {{ $a->last_name }}</h3><p>{{ $a->reference }} · {{ $a->groupLabel()??'Group not assigned' }}</p></div><div class="toolbar"><a class="button" href="{{ route('learning.index',$a) }}">Tasks</a><a class="button" href="{{ route('actions.index',$a) }}">Actions</a><a class="button" href="{{ route('attendance.apprentice',$a) }}">Attendance</a><a class="button" href="{{ route('journey.show',$a) }}">Journey</a></div></article>@empty<p>No apprentices match this scope.</p>@endforelse</section>
<details class="panel"><summary>How these figures and colours work</summary><p>Green identifies recorded approval/completion. Red identifies an overdue source deadline. Grey means no rating or insufficient information. No additional BRAG thresholds have been invented.</p><p>Marking becomes overdue after more than seven calendar days from the current submission. Qualification targets are twelve calendar months after each qualification’s chosen start. Linked actions follow their original task dates.</p><p>{{ $metrics['marking_date_checks'] }} awaiting submissions have missing submission dates; {{ $metrics['qualification_date_checks'] }} qualification records need date checks. Unknown data does not count as achievement or compliance.</p></details>
@endsection
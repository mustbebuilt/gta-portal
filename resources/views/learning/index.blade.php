@extends('layout')
@section('title', 'Training blocks and tasks')
@section('content')
<header class="page-heading"><div><p class="eyebrow">{{ $apprentice->full_name ?? ($apprentice->first_name.' '.$apprentice->last_name) }}</p><h1>Training blocks and tasks</h1><p class="muted">Open the task, complete the work, then send it to your tutor.</p></div><a class="button" href="{{ route('apprentices.show',$apprentice) }}">Apprentice profile</a><a class="button" href="{{ route('curriculum.show',$apprentice) }}">Blocks and KSB reference</a></header>
@include('learning.errors')
@if($tasks->isEmpty())
<section class="panel"><h2>No tasks assigned</h2><p>No progress percentage is available until tasks are assigned.</p>
@if($manage)<form method="post" action="{{ route('learning.allocate',$apprentice) }}">@csrf<label class="check"><input type="checkbox" name="confirmed" value="1" required>I confirm the supplied Autocare catalogue applies to this apprentice. This assigns source tasks only; it does not approve an individual training plan.</label><button class="primary">Assign Autocare tasks</button></form>@endif</section>
@else
@php($all=(int)$counts->sum())
@php($approved=(int)($counts['Approved']??0))
<section class="panel"><h2>Task approval</h2><div class="progress-summary"><div class="progress-ring" style="--progress:{{ round(100*$approved/$all) }};--progress-color:var(--achieved)"><strong>{{ round(100*$approved/$all) }}%</strong></div><div><p><strong>{{ $approved }} of {{ $all }} tasks approved</strong></p><p>Task approval only. Individual plan, KSB achievement and apprenticeship completion remain separate.</p><p class="muted">{{ $counts['Awaiting marking']??0 }} awaiting marking · {{ $counts['Referred']??0 }} returned for changes</p></div></div></section>
<form class="search panel" method="get"><div><label for="task-status">Show tasks</label><select id="task-status" name="status"><option value="">All statuses</option>@foreach(['Draft','Awaiting marking','Referred','Approved'] as $status)<option @selected(request('status')===$status)>{{ $status }}</option>@endforeach</select></div><button>Apply filter</button><a class="button" href="{{ route('learning.index',$apprentice) }}">Clear</a></form>
@forelse($tasks->groupBy('block') as $block=>$items)
<section class="panel"><h2>Block {{ $block }} · {{ $items->first()->theme }}</h2><div class="work-list">@foreach($items as $task)<article class="work-row"><div><h3><a href="{{ route('tasks.show',$task) }}">{{ $task->title }}</a></h3><p class="muted">Due: {{ $task->due_date?->format('d M Y') ?? 'Not set' }}</p></div><span class="status {{ $task->status==='Approved'?'achieved':($task->markingOverdue()?'red':'neutral') }}">{{ $task->markingOverdue()?'Overdue marking':$task->status }}</span><a class="button" href="{{ route('tasks.show',$task) }}">Open task</a></article>@endforeach</div></section>
@empty<section class="panel"><h2>No matching tasks</h2><p>Change the status filter to see other tasks.</p></section>@endforelse
<p class="muted">Blocks 11 and 12, sequential block release and formal KSB sign-off are not activated in this checkpoint.</p>
@endif
@endsection

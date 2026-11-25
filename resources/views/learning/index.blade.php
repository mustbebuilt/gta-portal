@extends('layout')
@section('title', 'Training blocks and tasks')
@section('content')
@include('quick-actions.context')
<header class="page-heading"><div><p class="eyebrow">{{ $apprentice->first_name }} {{ $apprentice->last_name }}</p><h1>Training blocks and tasks</h1></div></header>
@include('learning.errors')
@include('learning.source-warnings')
@if((int)$counts->sum()===0)
<section class="panel"><h2>No tasks assigned</h2><p>No progress percentage is available until tasks are assigned.</p>
@php($catalogueKey=\App\Support\TaskCatalogue::allocationKey($apprentice))
@if($manage && $catalogueKey)<p><a class="button" href="{{ route('task-library.index',['programme'=>$catalogueKey]) }}">Preview this task catalogue</a></p><form method="post" action="{{ route('learning.allocate',$apprentice) }}">@csrf<label class="check"><input type="checkbox" name="confirmed" value="1" required>I confirm the supplied {{ \App\Support\TaskCatalogue::PROGRAMMES[$catalogueKey]['name'] }} catalogue applies to this apprentice. This assigns source tasks only; it does not approve an individual training plan.</label><button class="primary">Assign {{ \App\Support\TaskCatalogue::PROGRAMMES[$catalogueKey]['name'] }} tasks</button></form>@elseif($manage)<p>Link an active supported programme with its correct standard and version before assigning tasks. An unlinked record does not default to Autocare.</p><a class="button" href="{{ route('apprentices.edit',$apprentice) }}">Check the programme link</a>@elseif(auth()->user()->role==='apprentice')<p>Ask your Tutor to check the programme and task allocation. You do not need to create another apprentice record or upload work against a different programme.</p>@else<p>Ask an administrator to check the programme and assign the correct source tasks.</p>@endif</section>
@else
@php($all=(int)$counts->sum())
@php($approved=(int)($counts['Approved']??0))
@if(($counts['Source required']??0)>0)<p class="notice">{{ $counts['Source required'] }} reflection records need their programme source. They cannot be submitted or approved. Administrators can load available sources into untouched placeholders. Records with work or history need individual investigation.</p>@endif
@if($manage && ($counts['Source required']??0)>0 && \App\Support\TaskCatalogue::keyFor($apprentice)==='panel')
<section class="panel"><h2>Panel reflection sources available</h2><p>Load the supplied reflections into untouched Source required placeholders. Saved answers, evidence and marking history are preserved. New reflections start as drafts and still need apprentice work and tutor approval.</p><form method="post" action="{{ route('learning.reflection-sources',$apprentice) }}">@csrf<label class="check"><input type="checkbox" name="confirmed" value="1" required>I confirm the supplied Panel reflection sources apply.</label><button class="primary">Load reflection sources</button></form></section>
@endif
<section class="panel" aria-labelledby="task-approval">
    <h2 id="task-approval">{{ $nextTask ? 'Continue your training' : 'Task approval' }}</h2>
    @if($nextTask)
        <h3>{{ $nextTask->title }}</h3>
        @if($nextTask->due_date)<p>Due {{ $nextTask->due_date->format('j M Y') }}</p>@endif
        <a class="button primary" href="{{ route('tasks.show', $nextTask) }}">{{ $nextTask->status === 'Referred' ? 'Make your task changes' : 'Open next task' }}</a>
    @endif
    <p><strong>{{ $approved }} of {{ $all }} tasks approved</strong></p>
    @if(($counts['Awaiting marking'] ?? 0) || ($counts['Referred'] ?? 0))
        <p>{{ $counts['Awaiting marking'] ?? 0 }} with the tutor for marking · {{ $counts['Referred'] ?? 0 }} returned for changes</p>
    @endif
</section>
<details class="panel" @if($status) open @endif>
    <summary>Filter tasks</summary>
    <form class="search" method="get">
        <div><label for="task-status">Show tasks</label><select id="task-status" name="status">
            <option value="">All statuses</option>
            @foreach($statuses as $option)<option value="{{ $option }}" @selected($status === $option)>{{ ['Referred' => 'Returned for changes', 'Awaiting marking' => 'With tutor for marking'][$option] ?? $option }}</option>@endforeach
        </select></div>
        <button>Apply filter</button>
        @if($status)<a class="button" href="{{ route('learning.index', $apprentice) }}">Clear</a>@endif
    </form>
</details>
@forelse($tasks->groupBy('block') as $block => $items)
    <details class="panel" @if($status || $block === $focusBlock) open @endif>
        <summary>Block {{ $block }} · {{ $items->first()->theme }}
            @if(!$status) · {{ $items->where('status', 'Approved')->count() }} of {{ $items->count() }} approved @endif
        </summary>
        <div class="work-list">
            @foreach($items as $task)
                @php($release = $releaseStates[$task->id])
                <article class="work-row">
                    <div>
                        <h3><a href="{{ route('tasks.show', $task) }}">{{ $task->title }}</a></h3>
                        <p class="muted">{{ $task->due_date ? 'Due '.$task->due_date->format('j M Y') : 'Due date not set' }}</p>
                        @if(!$release['open'])<p>{{ $release['label'] }}</p>@endif
                    </div>
                    <span class="status {{ $task->status === 'Approved' ? 'achieved' : ($task->markingOverdue() ? 'red' : 'neutral') }}">
                        {{ $task->markingOverdue() ? 'Overdue marking' : (['Referred' => 'Returned for changes'][$task->status] ?? $task->status) }}
                    </span>
                </article>
            @endforeach
        </div>
    </details>
@empty<section class="panel"><h2>No matching tasks</h2><p>{{ (int)$counts->sum() }} task(s) are already assigned. No tasks match this status filter; this does not mean the allocation is missing.</p><a class="button" href="{{ route('learning.index',$apprentice) }}">Show all assigned tasks</a></section>@endforelse
<p class="muted">Gateway, EPA and formal KSB assessment are recorded separately.</p>
@endif
<nav class="page-actions64" aria-label="Training page actions"><div class="toolbar"><a class="button" href="{{ route('apprentices.show',$apprentice) }}">Apprentice profile</a><a class="button" href="{{ route('delivery.show',$apprentice) }}">Delivery plan and block calendar</a></div>
<details class="action-disclosure64"><summary>Reference and source details</summary><ul><li><a href="{{ route('curriculum.show',$apprentice) }}">Blocks and KSB reference</a></li>@if(\App\Support\CurriculumSources::allowed(auth()->user(),$apprentice))<li><a href="{{ route('curriculum-sources.show',$apprentice) }}">Task source comparison</a></li>@endif</ul></details></nav>
@if($manage)@include('learning.block-controls')@endif
@endsection

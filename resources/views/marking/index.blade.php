@extends('layout')
@section('title','Marking worklist')
@section('content')
@if($manager)<section class="panel"><h2>In-portal reminder processing</h2><p>Daily job configuration: {{ $reminderScheduleEnabled?'Enabled':'Disabled' }}. No external messages are sent.</p><p>Last recorded combined run: {{ $lastReminderRun?->created_at??'No combined run recorded' }}. An enabled setting alone does not confirm regular processing; check the latest run date.</p></section>@endif

<header class="page-heading"><div><p class="eyebrow">{{ $manager?'Management oversight':'Your current apprentices' }}</p><h1>Marking worklist</h1><p>Open the submitted task, check its evidence and record the whole-task decision.</p></div></header>
@include('learning.errors')
<section class="panel"><h2>Awaiting a tutor decision</h2><p><strong>{{ $counts['overdue'] }} overdue</strong> · {{ $counts['awaiting'] }} awaiting marking · {{ $counts['date_checks'] }} submission dates need checking.</p><p class="muted">Overdue means more than seven calendar days after submission. Missing or future dates are checks, not zero days waiting. Seeing a reminder does not mark or approve a task.</p>
@if($manager)<form method="post" action="{{ route('marking.record') }}">@csrf<button>Create in-portal reminders</button><p class="muted">Uses all current eligible overdue submissions. Each submission is recorded once for its current active main tutor. No email is sent.</p></form>@endif</section>
<form class="search panel" method="get"><div><label for="marking-view">Show</label><select id="marking-view" name="view">@foreach(['overdue'=>'Overdue marking','all'=>'All awaiting marking','date-check'=>'Submission date checks','needs-owner'=>'Tutor assignment checks'] as $value=>$label)<option value="{{ $value }}" @selected($filter===$value)>{{ $label }}</option>@endforeach</select></div><div><label for="marking-search">Apprentice name or reference</label><input id="marking-search" name="search" value="{{ $search }}"></div><button>Apply filter</button><a class="button" href="{{ route('marking.index') }}">Clear</a></form>
<section class="panel"><h2>Submitted work</h2><div class="work-list">@forelse($tasks as $task)
@php($source=$sources[$task->id])
@php($reminder=$source?($reminders[$source['source_key']]??null):null)
<article class="work-row"><div><h3><a href="{{ route('tasks.show',$task) }}">{{ $task->title }}</a></h3><p>{{ $task->apprentice->reference }} · {{ $task->apprentice->first_name }} {{ $task->apprentice->last_name }} · Block {{ $task->block }}</p><p class="muted">Submitted: {{ $task->submitted_at?->format('d M Y H:i')??'Not recorded' }} · Main tutor: {{ $task->apprentice->tutor?->name??'Not assigned' }}</p>
@if(\App\Support\MarkingReminders::dateCheck($task))<p class="status amber">Submission date needs checking</p>@elseif($task->markingOverdue())<p class="status red">Overdue marking</p>@else<p class="status neutral">Awaiting marking</p>@endif
@if($task->markingOverdue() && !$source)<p class="muted">Check the current active tutor assignment and saved submission source before recording a reminder.</p>@endif
@if($reminder)<p class="muted">In-portal reminder: {{ $reminder->seen_at?'Seen '.$reminder->seen_at->format('d M Y H:i'):'Not yet seen' }}.</p>@endif</div><a class="button" href="{{ route('tasks.show',$task) }}">Open task</a>
@if($reminder && $reminder->recipient_id===auth()->id() && !$reminder->seen_at)<form method="post" action="{{ route('marking.seen',$reminder) }}">@csrf<button>Mark reminder seen</button></form>@endif</article>
@empty<p>No submitted tasks match this view.</p>@endforelse</div>{{ $tasks->links() }}</section>
@endsection

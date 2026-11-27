@extends('layout')
@section('title','Who needs attention today?')
@section('content')
<header class="page-heading"><div><p class="eyebrow">Staff follow-up · {{ today()->format('d M Y') }}</p><h1>Who needs attention today?</h1><p>Start with the next action for each apprentice. Open the other reasons when you need them.</p></div></header>
@include('learning.errors')
@if($nextRow = $rows->first())
    @include('staff-workflow.attention-row', ['row'=>$nextRow, 'nextAction'=>true])
@endif
@include('setup68.home-actions')
<details class="panel" @if($view !== 'all' || $reason !== 'all' || $q !== '') open @endif>
<summary>Filter apprentices</summary>
<form method="get"><div class="form-grid"><div><label for="attention-view">Priority</label><select id="attention-view" name="view">@foreach(\App\Support\StaffAttention::VIEWS as $v)<option value="{{ $v }}" @selected($view===$v)>{{ ucfirst($v) }}</option>@endforeach</select></div><div><label for="attention-reason">Reason</label><select id="attention-reason" name="reason">@foreach(\App\Support\StaffAttention::REASONS as $v)<option value="{{ $v }}" @selected($reason===$v)>{{ ucfirst($v) }}</option>@endforeach</select></div><div><label for="attention-search">Name or apprentice reference</label><input id="attention-search" name="q" value="{{ $q }}" maxlength="100"></div></div><button>Show apprentices</button></form>
</details>
<p><a href="{{ route('operations-desk.index', ['q'=>$q]) }}">Recorded staff follow-ups</a></p>
<p>{{ $rows->total() }} apprentices match these filters.</p>
@foreach($rows->skip(1) as $row)
    @include('staff-workflow.attention-row', ['nextAction'=>false])
@endforeach
@if($rows->isEmpty())
    <section class="panel"><h2>No matching follow-up reasons</h2><p>Try a different filter or open another work list.</p></section>
@endif
{{ $rows->links() }}
<details class="panel"><summary>Counts and how the list is prioritised</summary>
<div class="kpi-grid">@foreach($position['counts'] as $key=>$count)<section class="panel"><strong>{{ $count }}</strong><p>{{ ucfirst($key) }} · apprentices</p></section>@endforeach</div>
<p>Counts cover the authorised caseload before filters. {{ $position['scope_count'] }} profiles checked.</p>
<p>Overdue references first, then due today, follow-up prompts and source checks; earliest reference date first, then apprentice reference. Filters show only their matching reasons. Counts describe apprentices, not a combined score.</p><p>Training/review flags require a sourced Active programme status. Unknown status is a source check; paused/completed/withdrawn/transferred profiles do not receive historic training overdue flags. New permitted non-private help/achievement requests still appear.</p><p>Attendance uses saved completed sessions in the last six full calendar weeks plus this week. Two consecutive completed weeks with missed sessions and no attended or unmarked sessions create a follow-up reference. Holiday, sickness and kept-at-work count as missed under the existing register definition; this is not a misconduct finding. Other missed sessions in the last 14 days are follow-up prompts. Missing/invalid registers are checks, not absence.</p><p>Task marking uses the existing seven-day reference. Task/action dates must be recorded; blocked due tasks are plan checks. Linked task actions are not duplicated. Review dates come only from checked signed-copy history. No dated task approval for at least 30 days is a progress conversation prompt where incomplete allocated tasks and reliable programme/start/decision dates exist; it does not prove no learning occurred.</p><p>Mentors see review follow-up and permitted employer-help requests; main-training detail requires main-tutor/management permission. Private help is never included here.</p></details>
@endsection

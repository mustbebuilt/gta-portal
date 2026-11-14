@extends('layout')
@section('title','Progress reviews')
@section('content')
<header class="page-heading"><div><h1>Progress reviews</h1><p>Prepare review content, check externally signed copies and follow the next tracked meeting date.</p></div></header>
@include('learning.errors')
<p class="test-notice">Tracked dates use independently checked actual meetings. Electronic signing, external sharing and whole-history compliance assurance remain separate. No review compliance percentage is calculated.</p>
@include('reviews.followup-controls')
<section class="panel"><h2>Apprentices in your scope</h2>
@forelse($reviewRows as $reviewRow)
@php($a=$reviewRow['apprentice'])
@php($position=$positions[$a->id])
@php($cycle=$position['cycle'])
<article class="work-row" data-review-apprentice="{{ $a->id }}"><div><h3>{{ $a->first_name }} {{ $a->last_name }} · {{ $a->reference }}</h3><p>Mentor: {{ $a->mentor?->name??'Not assigned' }} · Tutor: {{ $a->tutor?->name??'Not assigned' }}</p>
<p><strong>{{ ['overdue'=>'Overdue checked reference','today'=>'Due today','checks'=>'Status or history needs checking','upcoming'=>'Upcoming checked reference','paused'=>'Paused or non-active programme'][$reviewRow['bucket']] }}</strong> · Last checked meeting {{ $cycle['last_meeting']??'Unknown' }} · Next tracked review {{ $cycle['next_due']??'Unknown — history to check' }}.</p><p>{{ $cycle['confirmed_count'] }} independently checked copies · {{ $cycle['basis'] }}.</p>
@if($position['programme_check'])<p>Programme status/source needs checking. No automatic reminder is generated.</p>@elseif($position['paused'])<p>Recorded programme status: {{ $position['programme_status'] }}. Automatic review reminders paused; existing review dates/history retained.</p>@endif
@if(!$cycle['next_due'])<p>First-review reference: {{ $cycle['first_review_reference']??'Start date not recorded' }}. Check completed history before treating this as a current due date; no automatic reminder is generated.</p>@endif
@if($position['due'])
@if($position['owner_checks'])<p class="test-notice">{{ $position['owner_checks'] }} tutor/mentor assignment(s) need checking before a reminder can be recorded.</p>@endif
@foreach($position['items'] as $item)
@if($manager || $item['owner']->id===auth()->id())
<div><p><strong>{{ ucfirst($item['source']['recipient_role']) }} reminder · {{ $item['owner']->name }}</strong> · @if(!$item['intact'])Source needs checking — acknowledgement unavailable.@elseif(!$item['reminder'])Not yet recorded.@elseif($item['reminder']->seen_at)Seen {{ $item['reminder']->seen_at->format('d M Y H:i') }}; review remains due.@else Awaiting assigned {{ $item['source']['recipient_role'] }} acknowledgement.@endif</p>
@if($item['intact'] && $item['reminder'] && !$item['reminder']->seen_at && $item['owner']->id===auth()->id())<form method="post" action="{{ route('reviews.reminders.seen',$item['reminder']) }}">@csrf<button>Mark review reminder seen</button></form>@endif
</div>
@endif
@endforeach
@endif
@include('reviews.draft-status')
</div><div class="toolbar"><a class="button" href="{{ route('reviews.create',$a) }}">Review history and drafts</a>@if($reviewRow['latest'])<a class="button" href="{{ route('reviews.edit',[$a,$reviewRow['latest']->id]) }}">Continue latest draft<span class="sr-only">: {{ $a->reference }}</span></a>@endif</div></article>
@empty<p>No apprentices match this review view. Clear the filters to see other records in your permitted scope.</p>@endforelse
<nav class="pagination" aria-label="Review follow-up pages">@if($reviewRows->previousPageUrl())<a class="button" href="{{ $reviewRows->previousPageUrl() }}">Previous reviews</a>@endif @if($reviewRows->nextPageUrl())<a class="button" href="{{ $reviewRows->nextPageUrl() }}">Next reviews</a>@endif</nav></section>
@if($manager)
<details class="panel"><summary>In-portal review reminders for all eligible sources</summary><p>This processes all eligible sources regardless of the filters above. Due today or overdue checked-meeting references for a recorded Active programme only. Each current tutor and mentor gets one reminder per source; marking it seen does not complete a review. Provisional first-review dates do not create reminders.</p>
<p><strong>{{ $reminderPreview['due'] }}</strong> apprentices due · {{ $reminderPreview['eligible'] }} eligible staff reminders · {{ $reminderPreview['already_recorded'] }} already recorded · {{ $reminderPreview['awaiting_seen'] }} recorded and awaiting acknowledgement.</p>
<p>Checks needed: {{ $reminderPreview['programme_checks'] }} programme status/source checks · {{ $reminderPreview['paused'] }} paused/non-active programmes excluded · {{ $reminderPreview['history_checks'] }} without a reliable completed-copy date · {{ $reminderPreview['owner_checks'] }} missing/inactive/wrong-role staff assignments for due references · {{ $reminderPreview['source_checks'] }} reminder source issues.</p>
<form method="post" action="{{ route('reviews.reminders.record') }}">@csrf<button class="primary">Record current due reminders</button></form>
<p>Automatic in-portal generation: <strong>{{ $reminderScheduleEnabled?'Configured on — actual server scheduler must be checked':'Off' }}</strong>. No email is sent. @if($lastReminderRun)Last combined recorded run: {{ $lastReminderRun->created_at }}. Earlier run counts retain their original scope.@else No combined run recorded.@endif</p></details>
@endif

@endsection

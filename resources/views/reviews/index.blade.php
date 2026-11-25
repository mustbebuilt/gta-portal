@extends('layout')
@section('title','Progress reviews')
@section('content')
<header class="page-heading"><div><h1>Progress reviews</h1></div></header>
@include('learning.errors')
@include('employer-resources65.dashboard-link')
<nav class="toolbar" aria-label="Progress review views">
    <a class="button" href="{{ route('reviews.index', request()->only('search', 'group', 'apprentice')) }}" @if(!$preparationView) aria-current="page" @endif>Review follow-up</a>
    <a class="button" href="{{ route('reviews.index', ['view' => 'preparation'] + request()->only('search', 'group', 'apprentice')) }}" @if($preparationView) aria-current="page" @endif>Prepare reviews</a>
</nav>
@if(!$preparationView) @include('reviews.triage') @endif
@include('reviews.followup-controls')
@if($triageView)
@include('reviews.triage-rows')
@else
<section class="panel"><h2>Apprentices in your scope</h2>
<p>{{ $reviewRows->total() }} matching apprentices @if($reviewRows->lastPage() > 1) · Page {{ $reviewRows->currentPage() }} of {{ $reviewRows->lastPage() }} @endif</p>
@forelse($reviewRows as $reviewRow)
@php($a=$reviewRow['apprentice'])
@php($position=$positions[$a->id])
@php($cycle=$position['cycle'])
<article class="work-row" data-review-apprentice="{{ $a->id }}">
<div>
    <h3>{{ $a->first_name }} {{ $a->last_name }} · {{ $a->reference }}</h3>
    <p><strong>{{ ['overdue'=>'Review overdue','today'=>'Due today','checks'=>'History or status to check','upcoming'=>'Upcoming review','paused'=>'Paused or non-active programme'][$reviewRow['bucket']] }}</strong>
        @if($cycle['next_due']) · Next tracked review {{ $cycle['next_due'] }} @endif
    </p>
    @if($reviewRow['latest'])
        <p>{{ match ($reviewRow['latest']->state) { 'Ready for signatures' => 'Saved review ready for signatures', 'Draft' => 'Saved review draft', default => 'Saved review status needs checking' } }}</p>
    @endif
    @if($preparationView)
        @include('reviews.preparation-details', ['prep' => $preparation[$a->id]])
    @endif
    <details>
        <summary>Dates, staff and reminders</summary>
        <p>Mentor: {{ $a->mentor?->name ?? 'Not assigned' }} · Tutor: {{ $a->tutor?->name ?? 'Not assigned' }}</p>
        <p>Last checked meeting {{ $cycle['last_meeting'] ?? 'Unknown' }} · {{ $cycle['confirmed_count'] }} checked copies · {{ $cycle['basis'] }}.</p>
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
</details>
</div>
<div class="toolbar">
    @if($preparationView)
        <a class="button primary" href="{{ $preparation[$a->id]['url'] }}">{{ $preparation[$a->id]['latest_id'] ? 'Open saved review' : 'Start review draft' }}</a>
        <a href="{{ route('review-workspace.show', $a) }}">Factual preparation worksheet</a>
    @elseif($reviewRow['latest'])
        <a class="button primary" href="{{ route('reviews.edit', [$a, $reviewRow['latest']->id]) }}">{{ $reviewRow['latest']->state === 'Ready for signatures' ? 'Open review sign-off' : 'Continue latest draft' }}<span class="sr-only">: {{ $a->reference }}</span></a>
        <a href="{{ route('reviews.create', $a) }}">Review history</a>
    @else
        <a class="button primary" href="{{ route('reviews.create', $a) }}">Start review / View history</a>
    @endif
    @if(\App\Support\ReviewAppointments::staff(auth()->user(), $a))
        <a href="{{ route('review-appointments.form', $a) }}">Book review appointment</a>
    @endif
</div>
</article>
@empty<p>No apprentices match this review view. Clear the filters to see other records in your permitted scope.</p>@endforelse
</section>
@endif
<nav class="pagination" aria-label="Review follow-up pages">@if($reviewRows->previousPageUrl())<a class="button" href="{{ $reviewRows->previousPageUrl() }}">Previous reviews</a>@endif @if($reviewRows->nextPageUrl())<a class="button" href="{{ $reviewRows->nextPageUrl() }}">Next reviews</a>@endif</nav>
<details class="panel"><summary>Review tools</summary><ul>
    <li><a href="{{ route('review-appointments.index') }}">Review appointments</a></li>
    <li><a href="{{ route('electronic-reviews.index') }}">Review signing</a></li>
</ul><p>Tracked due dates use checked meeting records. Bookings and signing have their own status.</p></details>
@if($manager)
<details class="panel"><summary>In-portal review reminders for all eligible sources</summary><p>This processes all eligible sources regardless of the filters above. Due today or overdue checked-meeting references for a recorded Active programme only. Each current tutor and mentor gets one reminder per source; marking it seen does not complete a review. Provisional first-review dates do not create reminders.</p>
<p><strong>{{ $reminderPreview['due'] }}</strong> apprentices due · {{ $reminderPreview['eligible'] }} eligible staff reminders · {{ $reminderPreview['already_recorded'] }} already recorded · {{ $reminderPreview['awaiting_seen'] }} recorded and awaiting acknowledgement.</p>
<p>Checks needed: {{ $reminderPreview['programme_checks'] }} programme status/source checks · {{ $reminderPreview['paused'] }} paused/non-active programmes excluded · {{ $reminderPreview['history_checks'] }} without a reliable completed-copy date · {{ $reminderPreview['owner_checks'] }} missing/inactive/wrong-role staff assignments for due references · {{ $reminderPreview['source_checks'] }} reminder source issues.</p>
<form method="post" action="{{ route('reviews.reminders.record') }}">@csrf<button class="primary">Record current due reminders</button></form>
<p>Automatic in-portal generation: <strong>{{ $reminderScheduleEnabled?'Configured on — actual server scheduler must be checked':'Off' }}</strong>. No email is sent. @if($lastReminderRun)Last combined recorded run: {{ $lastReminderRun->created_at }}. Earlier run counts retain their original scope.@else No combined run recorded.@endif</p></details>
@endif

@endsection

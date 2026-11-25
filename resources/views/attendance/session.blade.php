@extends('layout')
@section('title',$session->name)
@section('content')
<header class="page-heading"><div><p class="eyebrow">{{ $session->group->name }}</p><h1>{{ $session->name }}</h1><p>{{ $session->session_date->format('d M Y') }} · {{ $session->start_time }}–{{ $session->end_time }}</p><span class="status">{{ $session->state }}</span></div><a class="button" href="{{ route('attendance.index', $nextScope83) }}">Back to sessions</a></header>
@include('learning.errors')
@include('attendance.register-readiness')
@if($session->state === 'Cancelled')
    <section class="panel">
        <h2>Cancelled session</h2>
        <p>{{ $session->cancellation_reason }}</p>
        <p>Attendance and OTJ totals exclude this session.</p>
    </section>
@endif
<section class="panel"><h2>Planned learning</h2><p class="recorded-answer">{{ $session->planned_learning }}</p><p>Excluded periods: @forelse($session->breaks as $break){{ $break['start'] }}–{{ $break['end'] }} @empty None recorded @endforelse</p><p class="muted">The roster is a saved snapshot. Changing current group membership does not rewrite past attendance.</p></section>
@if($session->state !== 'Cancelled' && $registerReadiness['ended'])
<form method="post" data-compact-register data-start="{{ substr($session->start_time,0,5) }}" data-end="{{ substr($session->end_time,0,5) }}" action="{{ route('attendance.save',$session) }}">@csrf @foreach($nextScope83 as $key=>$value)<input type="hidden" name="next_scope[{{$key}}]" value="{{$value}}">@endforeach<input type="hidden" name="version" value="{{ $session->version }}">
@if($session->marks->isNotEmpty())
    <section class="panel" aria-labelledby="register-correction">
        <h2 id="register-correction">Correct this register</h2>
        <p>Change only the entries that need correcting. Confirmed OTJ is recalculated from the corrected record. The original entries stay in the history.</p>
        @include('shared.staff-template-picker', ['pickerLabel'=>'Correction reason template','family'=>'register_corrections','fieldNames'=>['correction_reason']])
        <label for="correction-reason">Reason for correction (when changing saved information)</label>
        <textarea id="correction-reason" name="correction_reason" maxlength="2000" @if($errors->has('correction_reason')) aria-invalid="true" @endif>{{ old('correction_reason') }}</textarea>
    </section>
@endif
@include('attendance.structured-training')
<section class="panel" data-register-bulk hidden>
    <h2>Full-session attendance</h2>
    <p>Only unmarked rows are available. Leave exceptions unselected and record them individually. Confirm eligible OTJ in each apprentice’s details.</p>
    <div class="form-actions">
        <button type="button" data-select-unmarked>Select unmarked</button>
        <button type="button" data-apply-full-session disabled>Mark selected attended · {{ $session->start_time }}–{{ $session->end_time }}</button>
    </div>
    <p data-bulk-attendance-status role="status"></p>
</section>
@foreach($members as $a)
@php($m=$session->marks->firstWhere('apprentice_id',$a->id))
<section class="panel" data-register-person><h2><a href="{{ route('training-journey72.show', $a) }}">{{ $a->first_name }} {{ $a->last_name }}</a></h2><p class="muted">{{ $a->reference }}</p><div class="form-grid"><div><label for="code-{{ $a->id }}">Attendance code</label><select id="code-{{ $a->id }}" name="marks[{{ $a->id }}][code]" @required($session->regular_week !== null)><option value="">Not marked</option>@foreach(\App\Support\AttendanceMath::CODES as $code=>$label)<option value="{{ $code }}" @selected(old('marks.'.$a->id.'.code',$m?->code)===$code)>{{ $label }}</option>@endforeach</select></div></div>@if(!$m)
<label class="check" data-bulk-row hidden><input type="checkbox" data-bulk-select> Include {{ $a->first_name }} {{ $a->last_name }} in full-session attendance</label>
@endif
<button type="button" data-full-session hidden>Attended for the full session</button><p class="muted" data-full-session-status role="status"></p>@if($m)<p class="muted">Recorded: {{$m->arrival??'Time not set'}}–{{$m->departure??'Time not set'}} · OTJ {{$m->otj_confirmed?number_format($m->otj_minutes/60,2).' hours':'Not confirmed'}}</p>@endif
@php($personErrors83=old('marks.'.$a->id)!==null || $errors->has('marks') || collect($errors->keys())->contains(fn($key)=>str_starts_with($key,'marks.'.$a->id.'.')))
<details @if($personErrors83)open @endif><summary>Actual times, OTJ and other details</summary><div class="form-grid"><div><label for="arrival-{{ $a->id }}">Actual arrival (attended codes)</label><input id="arrival-{{ $a->id }}" name="marks[{{ $a->id }}][arrival]" type="time" value="{{ old('marks.'.$a->id.'.arrival',$m?->arrival) }}"></div><div><label for="departure-{{ $a->id }}">Actual departure (attended codes)</label><input id="departure-{{ $a->id }}" name="marks[{{ $a->id }}][departure]" type="time" value="{{ old('marks.'.$a->id.'.departure',$m?->departure) }}"></div><div><label for="minutes-{{ $a->id }}">Eligible OTJ minutes, if reducing the proposal</label><input id="minutes-{{ $a->id }}" name="marks[{{ $a->id }}][minutes]" type="number" step="1" min="0" value="{{ old('marks.'.$a->id.'.minutes',$m?->otj_confirmed?$m->otj_minutes:null) }}"><p class="muted">Leave blank to use actual attendance less excluded periods. Never rounds up.</p></div><div><label for="adjustment-{{ $a->id }}">Reason for reducing eligible minutes</label><textarea id="adjustment-{{ $a->id }}" name="marks[{{ $a->id }}][adjustment]">{{ old('marks.'.$a->id.'.adjustment',$m?->adjustment) }}</textarea></div><div><label for="comment-{{ $a->id }}">Attendance comment</label><textarea id="comment-{{ $a->id }}" name="marks[{{ $a->id }}][comment]">{{ old('marks.'.$a->id.'.comment',$m?->comment) }}</textarea></div></div><label class="check"><input type="checkbox" name="marks[{{ $a->id }}][otj_confirmed]" value="1" @checked(old('marks.'.$a->id.'.otj_confirmed',$m?->otj_confirmed))>I checked that this apprentice’s recorded minutes are eligible OTJ, actually delivered in paid working time, with excluded activity removed.</label><p>Current confirmed OTJ: {{ $m?->otj_confirmed?number_format($m->otj_minutes/60,2).' hours':'Not confirmed' }}</p></details>
<details><summary>Individual training exceptions</summary>
@if($trainingOptions)
<div data-topic-exceptions="{{ $a->id }}" data-saved-exceptions="{{ json_encode(old('training.topic_exceptions.'.$a->id, $structuredTraining['topicExceptions'][$a->id] ?? []), JSON_THROW_ON_ERROR) }}"></div>
@endif
<a href="{{ route('apprentices.show', $a) }}#training-coverage">Open required training coverage</a>
@foreach(\App\Support\RegisterTraining::AREAS as $area=>$label)
<label>{{ $label }} <select name="training[exceptions][{{ $a->id }}][{{ $area }}]">
@foreach([''=>'As recorded for the group','missed'=>'Missed','partial'=>'Partly received'] as $value=>$text)
<option value="{{ $value }}" @selected(old("training.exceptions.{$a->id}.{$area}", $structuredTraining['exceptions'][$a->id][$area] ?? '') === $value)>{{ $text }}</option>
@endforeach
</select></label>
@endforeach
</details>
@include('attendance.monthly-development', ['record' => $monthlyDevelopment->get($a->id)])
</section>
@endforeach
<label class="check"><input type="checkbox" name="weekly_confirmed" value="1" @checked(old('weekly_confirmed'))> Confirm this register and its recorded training</label>
<div class="form-actions">@if($nextRegister83)<button class="primary" name="after_save" value="next">Save and open next register</button>@endif<button @class(['primary'=>!$nextRegister83]) name="after_save" value="stay">{{ $session->regular_week ? 'Submit register' : 'Save actual register' }}</button></div></form>
@elseif($session->state !== 'Cancelled')
    <section class="panel">
        <h2>Register opens after the session</h2>
        <p>Come back after {{ $session->end_time }} on {{ $session->session_date->format('d M Y') }} to record attendance and actual training.</p>
        <details>
            <summary>Saved roster · {{ $members->count() }} apprentices</summary>
            <ul>@foreach($members as $member)<li>{{ $member->first_name }} {{ $member->last_name }} · {{ $member->reference }}</li>@endforeach</ul>
        </details>
    </section>
@endif
@if($session->state !== 'Cancelled')
    <details class="panel" @if($errors->has('reason')) open @endif>
        <summary>Cancel session</summary>
        <p>Attendance and OTJ totals will exclude this session. The register history retains existing entries and the cancellation reason.</p>
        <form method="post" action="{{ route('attendance.cancel', $session) }}">
            @csrf
            <input type="hidden" name="version" value="{{ $session->version }}">
            @include('shared.staff-template-picker', ['pickerLabel'=>'Cancellation reason template','family'=>'register_cancellations','fieldNames'=>['reason']])
            <label for="cancel-reason">Cancellation reason</label>
            <textarea id="cancel-reason" name="reason" maxlength="2000" required>{{ old('reason') }}</textarea>
            <div class="form-actions"><button>Cancel this session</button></div>
        </form>
    </details>
@endif
<details class="panel"><summary>Register history</summary>@forelse(array_reverse($session->history??[]) as $h)<p>{{ $h['event'] === 'session_planned_subjects' ? 'Subjects planned' : $h['event'] }} · {{ $h['at'] }} · User {{ $h['by'] }}</p>@if($h['correction_reason']??$h['reason']??null)<p>{{ $h['correction_reason']??$h['reason'] }}</p>@endif
@empty<p>No register changes recorded.</p>@endforelse</details>
<script src="{{ asset('assets/routine-workflows.js') }}?v=87" defer></script>
@endsection
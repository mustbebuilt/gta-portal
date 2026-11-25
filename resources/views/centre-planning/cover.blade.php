@extends('layout')
@section('title','Check teaching cover')
@section('content')
@include('learning.errors')<h1>Check teaching cover</h1>@include('centre-planning.links')<section class="panel"><p>{{ $c->state }} · {{ $basis['row']['work_date'] }} {{ $basis['row']['start_time'] }}–{{ $basis['row']['end_time'] }} · {{ $basis['timezone'] }}</p><p>Cover: <strong>{{ $basis['cover_name'] }}</strong> · permanent group Tutor: {{ $basis['original_name'] }}</p><p>{{ $basis['row']['site'] }} · {{ \App\Models\TimetableRoom::find($basis['row']['room_id'])?->name }} · {{ \App\Models\TutorGroup::find($basis['row']['group_id'])?->name }}</p><p>Manager suitability/cover reference: {{ $c->source['reference'] }}</p><p>{{ $current?'Current checked cover planning':'Draft, withdrawn or source needs checking' }}. This grants no new register, learner profile, review, marking or support access. It does not record actual delivery or OTJ.</p></section>
@if($c->state === 'Active')
<section class="panel">
    <h2>Register for this session</h2>
    <p>{{ $basis['original_name'] }} or an administrator records attendance and actual learning using the cover tutor’s handover.</p>
    @if($register)
        <a class="button primary" href="{{ route('attendance.show', $register) }}">Open linked register</a>
    @elseif($canOpenGroupRegisters)
        <a class="button" href="{{ route('attendance.index', ['group' => $basis['row']['group_id']]) }}">Check this group’s registers</a>
    @endif
</section>
@endif
@if($c->state==='Draft')@foreach($checks['errors'] as $error)<p class="errors" role="alert">{{ $error }}</p>@endforeach<form method="post" action="{{ route('timetable-duties.decide',$c) }}">@csrf<input type="hidden" name="version" value="{{ $c->version }}"><input type="hidden" name="action" value="check"><label><input type="checkbox" name="confirmed" value="1" required> Check this exact class, Tutor/day source, clashes, suitability and register arrangements.</label><button @disabled((bool)$checks['errors'])>Record cover check</button></form>@if($c->check_proof)<form method="post" action="{{ route('timetable-duties.decide',$c) }}">@csrf<input type="hidden" name="version" value="{{ $c->version }}"><input type="hidden" name="action" value="publish"><label><input type="checkbox" name="confirmed" value="1" required> Publish the checked planning cover; permanent allocations and register permissions remain unchanged.</label><button>Publish planning cover</button></form>@endif
@endif
@if(in_array($c->state,['Draft','Active'],true))<form method="post" action="{{ route('timetable-duties.decide',$c) }}">@csrf<input type="hidden" name="version" value="{{ $c->version }}"><input type="hidden" name="action" value="withdraw"><label>Safe withdrawal reason <textarea name="reason" maxlength="2000" required>{{ old('reason') }}</textarea></label><label><input type="checkbox" name="confirmed" value="1" required> Withdraw only future cover planning; actual class/register is not cancelled.</label><button>Withdraw future cover</button></form>@endif
@endsection

@extends('layout')
@section('title','Prepare review appointment')
@section('content')
@include('learning.errors')
<h1>{{ $p?'Reschedule':'Book' }} review appointment</h1><p>{{ $a->reference }} · {{ $a->first_name }} {{ $a->last_name }}</p><p>One active appointment per apprentice. Saving creates a draft; publish after checking names and logistics. A reschedule withdraws the old portal appointment and requires fresh availability after republishing.</p>
@if(!$options)<p role="alert">Set up the apprentice’s verified individual login, a current named employer workplace grant and an assigned verified Tutor or Learning & Progress Mentor first. Workplace access is separate from review-signing authority.</p>@endif
<form method="post" action="{{ $p?route('review-appointments.save',[$a,$p]):route('review-appointments.store',$a) }}">@csrf<input type="hidden" name="request_token" value="{{ $token }}"><input type="hidden" name="version" value="{{ $p?->version??0 }}">
<label>Employer representative <select name="grant_id" required>@foreach($options  as $id=>$name)<option value="{{ $id }}" @selected(old('grant_id',$p?->source['basis']['grant_id'])==$id)>{{ $name }}</option>@endforeach</select></label>
<label>Assigned GTA review host <select name="host_id" required>@foreach($hosts  as $h)<option value="{{ $h->id }}" @selected(old('host_id',$p?->source['basis']['host_id'])==$h->id)>{{ $h->name }}</option>@endforeach</select></label>
@foreach(['date'=>'date','start'=>'time','end'=>'time'] as $k=>$type)<label>{{ ucfirst($k) }} ({{ config('app.timezone') }}) <input type="{{ $type }}" name="{{ $k }}" value="{{ old($k,$p?->source['logistics'][$k]) }}" required></label>@endforeach
<label>Meeting mode <select name="mode">@foreach(\App\Support\ReviewAppointments::MODES  as $m)<option @selected(old('mode',$p?->source['logistics']['mode'])===$m)>{{ $m }}</option>@endforeach</select></label>
<label>Shared location or safe joining instructions <input name="place" maxlength="500" value="{{ old('place',$p?->source['logistics']['place']) }}" required></label><p>All three participants can read this. Keep passwords and private review/support information out. Links are shown as text.</p><label><input type="checkbox" name="confirmed" value="1" required> I checked the named participants and safe logistics.</label><button @disabled(!$options)>Save draft</button></form>
@endsection

@extends('layout')
@section('title','Check timetable proposal')
@section('content')
<h1>Check timetable proposal</h1>@include('learning.errors')
<section class="panel">@include('optimiser.comparison')</section>
@if($preview['can_apply'])<form method="post" action="{{ route('optimiser.prepare') }}" class="panel">@csrf<input type="hidden" name="date_flex" value="{{ $preview['date_flex']??0 }}"><input type="hidden" name="from" value="{{ $preview['from'] }}"><input type="hidden" name="to" value="{{ $preview['to'] }}"><input type="hidden" name="source_hash" value="{{ $preview['source_hash'] }}"><input type="hidden" name="request_token" value="{{ $token }}">@foreach($preview['ids'] as $id)<input type="hidden" name="plans[]" value="{{ $id }}">@endforeach<label for="reason">Reason and arrangements reference</label><textarea id="reason" name="reason" required minlength="5" maxlength="1000"></textarea><label class="check"><input type="checkbox" name="confirmed" value="1" required>I have checked this proposal. Save it for my final review; do not move anything yet.</label><button class="primary">Save proposal for final check</button></form>@endif
<p><a href="{{ route('optimiser.index',['from'=>$preview['from'],'to'=>$preview['to']]) }}">Choose plans again</a></p>
@endsection

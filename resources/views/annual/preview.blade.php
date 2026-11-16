@extends('layout')
@section('title','Review full-year proposal')
@section('content')
<h1>Review full-year proposal</h1>@include('learning.errors')<section class="panel">@include('annual.comparison')</section>
@if($preview['can_apply'])<form method="post" action="{{ route('annual.prepare') }}" class="panel">@csrf
@foreach(['from','to','date_mode','teaching_dates'] as $key)<input type="hidden" name="{{ $key }}" value="{{ is_bool($preview['request'][$key])?(int)$preview['request'][$key]:$preview['request'][$key] }}">@endforeach
@foreach($preview['request']['plans'] as $id)<input type="hidden" name="plans[]" value="{{ $id }}">@endforeach
@foreach(['staff_options','room_options'] as $key) @foreach($preview['request'][$key] as $id=>$values) @foreach($values as $value)<input type="hidden" name="{{ $key }}[{{ $id }}][]" value="{{ $value }}">@endforeach @endforeach @endforeach
@foreach($preview['request']['pins']??[] as $id=>$pin) @foreach($pin as $key=>$value)<input type="hidden" name="pins[{{ $id }}][{{ $key }}]" value="{{ $value }}">@endforeach @endforeach
<input type="hidden" name="source_hash" value="{{ $preview['source_hash'] }}"><input type="hidden" name="request_token" value="{{ $token }}">
<label>Reason and agreed arrangements reference<textarea name="reason" required minlength="5" maxlength="1000"></textarea></label>
@if($preview['staff_suitability_required'])<label class="check"><input type="checkbox" name="staff_suitability" value="1" required>I have checked the nominated staff are suitable for these activities and the allocations are agreed.</label>@endif
<label class="check"><input type="checkbox" name="confirmed" value="1" required>Save this exact proposal for my final check. Do not move anything yet.</label><button class="primary">Save full-year proposal for final check</button></form>@endif
<p><a href="{{ route('annual.index',['from'=>$preview['from'],'to'=>$preview['to']]) }}">Back to full-year planning</a></p>
@endsection

@extends('layout')
@section('title','Check planning series')
@section('content')
@include('learning.errors')<h1>{{ $source['data']['args']['title'] }}</h1>@include('centre-planning.links')<p>{{ $p->state }} · version {{ $p->version }} · {{ $source['data']['staff']['name'] }} · {{ $source['data']['timezone'] }}</p><p>{{ $current?'Source current':'Source changed — retain reservation, reconcile explicitly' }}</p><div class="table-scroll" role="region" aria-label="Planning series dates and activities" tabindex="0"><table><thead><tr><th>Date</th><th>Time</th><th>Activity</th><th>Site/room</th><th>Group/headcount</th>@if($registerActions)<th>Register</th>@endif</tr></thead><tbody>@foreach($source['data']['rows'] as $row)<tr><td>{{ $row['work_date'] }}</td><td>{{ $row['start_time'] }}–{{ $row['end_time'] }}</td><td>{{ $row['kind'] }}</td><td>{{ $row['site'] }} · {{ $source['data']['room']['fields']['name']??'No room' }}</td><td>{{ $source['data']['group']['name']??'No group' }} · {{ $row['headcount']??'N/A' }}</td>
@if($registerActions)
<td>
    @if(isset($registerActions[$row['work_date']]))
        @php($registerAction = $registerActions[$row['work_date']])
        @if($registerAction['session'])
            <a href="{{ route('attendance.show', $registerAction['session']) }}">Open matching register</a>
        @elseif($registerAction['review'] || $registerAction['canCreate'])
            <a href="#occurrence-register-{{ $registerAction['entry']->id }}">{{ $registerAction['review'] ? 'Review register' : 'Set up register' }}</a>
        @endif
    @endif
</td>
@endif
</tr>@endforeach</tbody></table></div>
@if($p->state==='Draft')<h2>Current checks</h2>@foreach($check['errors'] as $error)<p class="errors" role="alert">{{ $error }}</p>@endforeach @foreach($check['warnings'] as $warning)<p role="alert">Check: {{ $warning }}</p>@endforeach @if(!$check['errors']&&!$check['warnings'])<p>No current clash/source errors found. This checks saved records, not real attendance or complete external calendars.</p>@endif<p><a href="{{ route('centre-planning.edit',$p) }}">Update draft after correcting source/time</a></p><form method="post" action="{{ route('centre-planning.decide',$p) }}">@csrf<input type="hidden" name="version" value="{{ $p->version }}"><input type="hidden" name="action" value="check"><label><input type="checkbox" name="warnings_accepted" value="1"> I checked all listed warnings and unknown external locations.</label><label><input type="checkbox" name="confirmed" value="1" required> I checked exact dates, staff working time, room suitability/capacity and possible conflicts.</label><button @disabled((bool)$check['errors'])>Record check</button></form>@if($p->check_proof)<p>Check recorded by {{ \App\Models\User::find($p->check_proof['by'])?->name??'staff source needing a check' }}. Source/conflicts are checked again on publication.</p><form method="post" action="{{ route('centre-planning.decide',$p) }}">@csrf<input type="hidden" name="version" value="{{ $p->version }}"><input type="hidden" name="action" value="publish"><label><input type="checkbox" name="confirmed" value="1" required> Publish this exact checked planning series.</label><button>Publish timetable</button></form>@endif
@endif
@foreach($registerActions as $action)
    @include('centre-planning.occurrence-register')
@endforeach
@if(in_array($p->state,['Draft','Published'],true))<h2>Withdraw future occurrences</h2><p>This withdraws every future occurrence in this series. Started/past planning remains historical. It does not cancel an existing register or alter attendance/OTJ. For a change, withdraw future occurrences then prepare a new series for the remaining dates.</p><form method="post" action="{{ route('centre-planning.decide',$p) }}">@csrf<input type="hidden" name="version" value="{{ $p->version }}"><input type="hidden" name="action" value="withdraw"><label>Safe planning reason <textarea name="reason" maxlength="2000" required>{{ old('reason') }}</textarea></label><label><input type="checkbox" name="confirmed" value="1" required> Withdraw future planning only.</label><button>Withdraw future dates</button></form>@endif
@endsection

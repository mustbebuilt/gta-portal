<section class="panel"><h2>Your next register</h2>
@if($nextRegister83)
<p><strong>{{$nextRegister83['session']->group->name}}</strong> · {{$nextRegister83['session']->session_date->format('D j M Y')}} · {{$nextRegister83['session']->name}}</p>
<p>{{$nextRegister83['missing_codes']}} attendance codes to record.</p>
<a class="button primary" href="{{route('attendance.show',['session'=>$nextRegister83['session'],...$nextScope83])}}">Open next register</a>
@else<p>No opened registers are due. Use Weekly groups and schedules to open an agreed session.</p>@endif
</section>

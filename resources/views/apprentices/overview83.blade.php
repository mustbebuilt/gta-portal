<section class="panel" aria-label="Apprentice overview">
<h2>At a glance</h2><div class="metrics">
<div class="metric">Tasks approved<strong>{{$overview83['task_total'] ? round(100*$overview83['task_approved']/$overview83['task_total']).'%' : 'Not available'}}</strong><small>{{$overview83['task_approved']}} of {{$overview83['task_total']}} tasks · task approval only</small></div>
<div class="metric">Attendance<strong>{{$overview83['attendance_check'] ? 'Needs checking' : (($overview83['attendance']['percent']??null)!==null ? round($overview83['attendance']['percent']).'%' : 'Not available')}}</strong></div>
@if($otjHeadline)
<div class="metric">Recorded OTJ<strong>{{ $otjHeadline['recorded_hours'] === null ? 'Not available' : number_format($otjHeadline['recorded_hours'], 2).' hours' }}</strong>
    @if($otjHeadline['expected_hours'] !== null)<small>{{ number_format($otjHeadline['expected_hours'], 2) }} hours expected to date</small>@endif
    @if($otjHeadline['needs_check'])<small>Some records need checking</small>@endif
    <a href="{{ route('otj.show', $apprentice) }}">Open OTJ record</a>
</div>
@endif
<div class="metric">Next review reference<strong>{{$overview83['review_due']??'Check review history'}}</strong></div>
</div>
@if($overview83['next'])<p><strong>Next action:</strong> {{$overview83['next']['title']}} <a class="button primary" href="{{$overview83['next']['url']}}">Open next action</a></p>@else<p>No due training actions in this view.</p>@endif
</section>

@if(\App\Support\LearningAccess::manager(auth()->user()) && !\App\Support\DeliveryPlan::approved($apprentice))
<details class="panel"><summary>Training plan setup incomplete</summary><form method="post" action="{{route('setup68.resume-existing',$apprentice)}}">@csrf<button>Complete apprentice setup</button></form><a href="{{route('delivery.show',$apprentice)}}">Open individual delivery plan</a></details>
@endif

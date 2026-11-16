<section class="panel" aria-labelledby="gateway-summary-heading"><h2 id="gateway-summary-heading">What is left before Gateway?</h2>
@if(!$gatewaySummary['current'])
<p>Gateway preparation is not assessed here yet. The Tutor needs to check the programme/cohort criteria and arrange independent approval.</p><p>No approved current criteria does not mean there are no requirements left.</p>
@else
<p>{{ $gatewaySummary['counts']['Met'] }} of {{ $gatewaySummary['total'] }} configured checks met · {{ $gatewaySummary['counts']['Missing'] }} missing · {{ $gatewaySummary['counts']['Unknown'] }} to check.</p>
@if($gatewaySummary['all_met'])
<p>All configured checks are recorded as met. The provider must still review the complete requirements and make the separate Gateway decision.</p>
@else
<p>Start with these outstanding checks:</p><ul>@foreach($gatewaySummary['preview'] as $item)<li><a href="{{ $item['url'] }}">{{ $item['label'] }}</a> · {{ $item['category'] }} · {{ $item['state']==='Unknown'?'To check':$item['state'] }}</li>@endforeach</ul>
@if(count($gatewaySummary['outstanding'])>3)<p>{{ count($gatewaySummary['outstanding'])-3 }} more outstanding check(s) are listed on the preparation page.</p>@endif
@endif
@endif
<a class="button" href="{{ $gatewaySummary['url'] }}">Open Gateway preparation checks</a><p class="muted">Configured checks only; this does not confirm KSB achievement, complete training, approve Gateway or book EPA. Future plans do not move the journey marker.</p>
</section>

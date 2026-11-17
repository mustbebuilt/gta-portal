@php
 $values=collect($chart['rows'])->flatMap(fn($r)=>[$r['value']??null,$r['planned']??null])->filter(fn($v)=>$v!==null);
 $max=max(1,(float)($values->max()??1));$chartId='p66-'.$chart['key'];
@endphp
<article class="p66-chart" aria-labelledby="{{ $chartId }}"><header><h3 id="{{ $chartId }}">{{ $chart['title'] }}</h3><span>{{ $chart['unit'] }}</span></header>
<p class="p66-note">{{ $chart['note'] }}</p>
@if($chart['type']==='stacked')
@php($sum=array_sum(array_column($chart['rows'],'value')))
<div class="p66-stack" role="img" aria-label="Task status distribution. Exact labelled figures follow.">@foreach($chart['rows'] as $r)@if($sum>0&&$r['value']>0)<span class="p66-swatch-{{ $loop->index%6 }}" style="flex:{{ $r['value'] }}"></span>@endif @endforeach</div>
<ul class="p66-legend">@foreach($chart['rows'] as $r)<li><i class="p66-swatch-{{ $loop->index%6 }}" aria-hidden="true"></i>{{ $r['label'] }} <strong>{{ $r['value']??'Not available' }}</strong></li>@endforeach</ul>
@elseif($chart['type']==='bars')
@foreach($chart['rows'] as $r)<div class="p66-bar-label"><span>{{ $r['label'] }}</span><strong>{{ $r['value']??'Not available' }}</strong></div><progress max="{{ $max }}" value="{{ $r['value']??0 }}" aria-label="{{ $r['label'] }}"></progress>@if($chart['key']==='groups')<p class="p66-note">Cohort {{ $r['cohort'] }} · allocated tasks {{ $r['tasks'] }}</p>@endif
@endforeach
@else
<div class="p66-plot-legend"><span>● {{ $chart['key']==='attendance'?'Attendance':'Actual recorded' }}</span>@if($chart['type']==='line')<span>┄ {{ $chart['key']==='otj-history'?'Current plan due':'Expected tasks' }}</span>@endif<span>Scale 0–{{ round($max,1) }}</span></div>
<svg class="p66-plot" viewBox="0 0 600 190" preserveAspectRatio="none" role="img" aria-label="{{ $chart['title'] }}. Open the exact figures table for every date and value.">
@foreach([0,1,2,3] as $g)<line x1="10" x2="590" y1="{{ 10+$g*55 }}" y2="{{ 10+$g*55 }}" class="p66-gridline"/>@endforeach
@if($chart['type']==='columns')
@foreach($chart['rows'] as $r)@if($r['value']!==null)@php($w=570/max(1,count($chart['rows'])))<rect x="{{ 15+$loop->index*$w }}" y="{{ 175-165*$r['value']/$max }}" width="{{ max(2,$w-7) }}" height="{{ 165*$r['value']/$max }}" rx="3" class="p66-column"/>@endif @endforeach
@else
@foreach(['value','planned'] as $series)
@php($segment=[])
@foreach($chart['rows'] as $r)
@php($v=$r[$series]??null)
@if($v===null)
@if(count($segment)>1)<polyline points="{{ implode(' ',$segment) }}" class="p66-line {{ $series==='planned'?'p66-planned':'' }}"/>@endif
@php($segment=[])
@else
@php($x=15+$loop->index*570/max(1,count($chart['rows'])-1)) @php($y=175-165*$v/$max) @php($segment[]=$x.','.$y)
<circle cx="{{ $x }}" cy="{{ $y }}" r="3.5" class="{{ $series==='planned'?'p66-plan-dot':'p66-dot' }}"/>
@endif
@endforeach
@if(count($segment)>1)<polyline points="{{ implode(' ',$segment) }}" class="p66-line {{ $series==='planned'?'p66-planned':'' }}"/>@endif
@endforeach
@endif</svg>
<div class="p66-axis"><span>{{ $chart['rows'][0]['label']??'' }}</span><span>{{ $chart['rows'][count($chart['rows'])-1]['label']??'' }}</span></div>
@endif
<details class="p66-exact"><summary>Exact figures: {{ $chart['title'] }}</summary><div class="p66-table-scroll" role="region" tabindex="0" aria-label="Exact {{ $chart['title'] }} figures"><table><caption>{{ $chart['title'] }} · {{ $report['demo']?'Demonstration — fictional data':($chart['key']==='attendance'?$report['period']:'Current records as of '.$report['source_as_of']) }}</caption><thead><tr><th scope="col">Label</th><th scope="col">Actual / count</th>@if($chart['type']==='line')<th scope="col">Planned reference</th>@endif @if($chart['key']==='attendance')<th scope="col">Attended / expected</th><th scope="col">Unmarked</th>@endif @if($chart['key']==='groups')<th scope="col">Cohort</th><th scope="col">Allocated tasks</th>@endif</tr></thead><tbody>@foreach($chart['rows'] as $r)<tr><th scope="row">{{ $r['label'] }}</th><td>{{ $r['value']??'Not available' }}</td>@if($chart['type']==='line')<td>{{ $r['planned']??'Not available' }}</td>@endif @if($chart['key']==='attendance')<td>{{ $r['attended'] }} / {{ $r['expected'] }}</td><td>{{ $r['unmarked'] }}</td>@endif @if($chart['key']==='groups')<td>{{ $r['cohort'] }}</td><td>{{ $r['tasks'] }}</td>@endif</tr>@endforeach</tbody></table></div></details>
</article>

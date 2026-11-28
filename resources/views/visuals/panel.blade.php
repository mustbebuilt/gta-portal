<section class="panel visual-panel" data-visual-panel="{{ $chart['key'] }}"><h2>{{ $chart['title'] }}</h2><details class="figure-help"><summary>About this measure</summary><p>{{ $chart['note'] }}</p></details><p class="visual-unit">Unit: {{ $chart['unit'] }}.@if(($chart['type']??'')==='grouped') {{ $chart['first'] }} / {{ $chart['second'] }}.@endif</p>
@php($hasChartValues=collect($chart['rows'])->contains(fn($row)=>($row['value']??0)>0||($row['second']??0)>0))
@if(!$hasChartValues)<div class="visual-empty"><strong>No positive recorded values in this scope</strong>See the table for zero and unknown values.</div>@endif
<div class="visual-chart" data-visual-chart>
    @if(!empty($chart['compact']))
        @include('visuals.metric-bars')
    @else
        {!! \App\Support\PortalCharts::svg($chart) !!}
    @endif
</div>
<div class="table-scroll visual-table" data-visual-table>
    @include('visuals.exact-table')
</div>
</section>

@extends('pdf.layout')
@section('title',$report['title'])
@section('content')
<style>body{font-size:9px}h1{font-size:21px}h2{font-size:14px;margin:16px 0 8px}.chart-image{width:100%;max-height:330px}.chart-section{page-break-inside:avoid;margin-bottom:14px}table{width:100%;border-collapse:collapse;table-layout:fixed}th,td{padding:5px;border:1px solid #cbd2db;overflow-wrap:anywhere;text-align:left}thead{display:table-header-group}tr{page-break-inside:avoid}.panel{margin:12px 0}.table-scroll{overflow:visible}</style>
<h1>{{ $report['title'] }}</h1><p>{{ $report['scope_label'] }} · {{ $report['record_count'] }} apprentice record(s). Captured {{ $report['captured_at'] }}. Attendance period {{ $report['attendance_from'] }} to {{ $report['attendance_to'] }}; other context is current at capture.</p>
@foreach($report['charts'] as $chart)<div class="chart-section"><h2>{{ $chart['title'] }}</h2><p>{{ $chart['note'] }} Unit: {{ $chart['unit'] }}.</p><img class="chart-image" alt="{{ $chart['title'] }}" src="data:image/svg+xml;base64,{{ base64_encode(\App\Support\PortalCharts::svg($chart)) }}"><table><thead><tr><th>Measure</th><th>{{ $chart['first']??'Recorded value' }}</th>@if(($chart['type']??'')==='grouped')<th>{{ $chart['second'] }}</th>@endif</tr></thead><tbody>@foreach($chart['rows'] as $row)<tr><td>{{ $row['label'] }}</td><td>{{ $row['value']===null?'Unknown':$row['value'] }}</td>@if(($chart['type']??'')==='grouped')<td>{{ $row['second']??'Unknown' }}</td>@endif</tr>@endforeach</tbody></table></div>@endforeach
@include('visual-reports.tables',['pdf'=>true])
<div style="page-break-inside:avoid"><h2>Definitions and limits</h2><ul>@foreach($report['definitions'] as $text)<li>{{ $text }}</li>@endforeach</ul></div>
@endsection

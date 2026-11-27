@extends('layout')
@section('title',$report['title'])
@section('content')
<header class="page-heading"><div><h1>{{ $report['title'] }}</h1><p>{{ $report['scope_label'] }} · {{ $report['record_count'] }} apprentice record(s).</p><p>Data captured {{ \Carbon\Carbon::parse($report['captured_at'])->timezone('Europe/London')->format('d M Y H:i') }} UK time.</p></div><div class="toolbar"><a class="button primary" href="{{ route('visual-reports.pdf',$report['filters']) }}">Download full PDF</a><button type="button" data-print>Print report</button><a class="button" href="{{ route('dashboard.index',array_filter(request()->only('group','apprentice'))) }}">Back to dashboard</a></div></header>
@include('learning.errors')
<form class="panel search" method="get">@foreach(['group','apprentice'] as $key)@if($report['filters'][$key])<input type="hidden" name="{{ $key }}" value="{{ $report['filters'][$key] }}">@endif @endforeach<label>Attendance from <input type="date" name="from" value="{{ $report['attendance_from'] }}" required></label><label>Attendance to <input type="date" name="to" value="{{ $report['attendance_to'] }}" required></label><button>Update attendance period</button></form>
<p>Charts use the same values as the supporting tables. Current tasks, training and plans are captured now; attendance covers {{ $report['attendance_from'] }} to {{ $report['attendance_to'] }}. Unknown values are shown explicitly.</p>
@php($attendanceChart = collect($report['charts'])->firstWhere('key', 'attendance-trend'))
@if($report['attendance_blocked'])
    <p class="notice" role="status">Attendance figures are unavailable for this period. Ask an administrator to check the saved register rosters. The other findings are available below.</p>
@endif
@if($attendanceChart)
    @include('visuals.panel', ['chart' => $attendanceChart])
    @php($attendance = $report['attendance_total'])
    @php($rating = \App\Support\ReportThresholds::internal($attendance['percent'] ?? null, 'attendance', $attendance['expected'] ?? null))
    <section class="panel">
        <h2>Attendance across the selected period</h2>
        <p><strong>{{ isset($attendance['percent']) ? number_format($attendance['percent'], 1).'%' : 'Unknown' }}</strong>
            <span class="report-rag report-rag-{{ $rating['status'] }}">{{ $rating['label'] }}</span></p>
        @if($attendance)<p>{{ $attendance['attended'] }} of {{ $attendance['expected'] }} expected apprentice-sessions · {{ $attendance['unmarked'] }} unmarked.</p>@endif
        <details><summary>GTA attendance thresholds</summary><p>{{ $rating['rule'] }}</p></details>
    </section>
@endif
@php($taskChart=collect($report['charts'])->firstWhere('key','tasks'))
@if($taskChart)@include('visuals.task-summary')@endif
@include('visuals.controls')
<div class="visual-grid">@foreach($report['charts'] as $chart)@continue($chart['key'] === 'attendance-trend')@include('visuals.panel')@endforeach</div>
@include('reports.headline', [
    'title' => 'Off-the-job training follow-up',
    'basis' => 'Source checks on the current occupational training ledger.',
    'items' => [
        ["Apprentices with pending OTJ sources", collect($report['details'])->where('otj_pending', '>', 0)->count(), 'exceptions'],
        ["Apprentices with invalid OTJ sources", collect($report['details'])->where('otj_checks', '>', 0)->count(), 'exceptions']
    ],
])
@if(auth()->user()->role !== 'apprentice')
@include('visual-reports.otj-followup')
@endif
@include('visual-reports.tables')
<details class="panel" data-print-expand><summary>Definitions and limits</summary><ul>@foreach($report['definitions'] as $text)<li>{{ $text }}</li>@endforeach</ul></details>
@endsection

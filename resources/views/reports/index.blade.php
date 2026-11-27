@extends('layout')
@section('title','Reports')
@section('content')
<header class="page-heading"><div><h1>Reports</h1><p>Choose a report, then open the records behind the figures.</p></div></header>
@php
    $categories = [
        'Who needs attention?' => ['dashboard.report', 'training-gaps72.index', 'support.report', 'retention.employer-attendance'],
        'How are apprentices progressing?' => ['visual-reports.index', 'cohort-insights.index', 'achievers-leavers.index'],
        'How are we performing?' => ['retention.report', 'historical-cohorts.index', 'inspection.index', 'management.index', 'timetable-management.index'],
    ];
    $categorised = array_merge(...array_values($categories));
    if ($other = array_diff(array_keys($reports), $categorised)) {
        $categories['Other reports'] = $other;
    }
@endphp
@if($headlines)
    <h2 id="headline-measures">Headline figures</h2>
    <div class="report-cards" aria-labelledby="headline-measures">
        @foreach($headlines as $headline)
            <article class="report-card">
                <h3><a href="{{ $headline['href'] }}">{{ $headline['name'] }}</a></h3>
                <strong>{{ $headline['value'] === null ? 'Unknown' : ($headline['percentage'] ? number_format($headline['value'], 1).'%' : $headline['value']) }}</strong>
                <span class="report-rag report-rag-{{ $headline['status'] }}" title="{{ $headline['rule'] }}">{{ $headline['label'] }}</span>
                <p>{{ $headline['detail'] }}</p>
            </article>
        @endforeach
    </div>
    <details class="panel">
        <summary>GTA thresholds</summary>
        <ul>@foreach(collect($headlines)->pluck('rule')->unique() as $rule)<li>{{ $rule }}</li>@endforeach</ul>
        <p>Missing or incomplete sources are shown as unknown where they prevent a reliable result.</p>
    </details>
@endif
<h2>Explore reports</h2>
<div class="dashboard-grid">
    @foreach($categories as $heading => $routes)
        @php($available = collect($routes)->filter(fn ($route) => isset($reports[$route])))
        @if($available->isNotEmpty())
            <section class="panel">
                <h2>{{ $heading }}</h2>
                <ul>
                    @foreach($available as $route)
                        <li><a href="{{ route($route) }}">{{ $reports[$route] }}</a></li>
                    @endforeach
                </ul>
            </section>
        @endif
    @endforeach
</div>
@if(isset($reports['retention.report']))
    <details class="panel">
        <summary>Apprenticeship Accountability Framework thresholds</summary>
        <p>Provider-level benchmarks. This dashboard has no verified ILR/QAR feed. Use the published accountability dashboard for the provider position.</p>
        <div class="table-scroll" role="region" aria-label="Framework thresholds" tabindex="0">
            <table><thead><tr><th scope="col">Measure</th><th scope="col">Above or within benchmark</th><th scope="col">Needs improvement</th><th scope="col">At risk</th></tr></thead><tbody>
                @foreach(\App\Support\ReportThresholds::benchmarks() as [$measure, $green, $amber, $red])
                    <tr><th scope="row">{{ $measure }}</th><td>{{ $green }}</td><td>{{ $amber }}</td><td>{{ $red }}</td></tr>
                @endforeach
            </tbody></table>
        </div>
        <p><a href="https://www.gov.uk/government/publications/apprenticeship-training-provider-accountability-framework/apprenticeship-training-provider-accountability-framework-and-specification--2">Read the published framework</a></p>
    </details>
@endif
@endsection

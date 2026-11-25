@extends('layout')
@section('title','Reports')
@section('content')
<header class="page-heading"><h1>Reports</h1></header>
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
@endsection

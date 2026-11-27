@extends('layout')
@section('title','Support and Retention reporting')
@section('content')
<header class="page-heading"><h1>Support and Retention reporting</h1><a class="button" href="{{ route('reports.index') }}">Reports</a></header>
@include('reports.headline', [
    'title' => 'Support and review position',
    'basis' => 'Current authorised scope. Categories count distinct apprentices and may overlap.',
    'items' => [
        ["Apprentices on register", $allCount],
        ["Need marker review", $dueCount, 'exceptions'],
        ["Have overdue support action", $overdueActions, 'exceptions']
    ],
])
<section class="panel">
<p>Current position · {{ $by }}</p>
<p>{{ $allCount }} apprentices in scope · {{ $dueCount }} need a marker review · {{ $overdueActions }} have an overdue support action</p>
<p>{{ $delivered }} recorded support activities · {{ $impact }} support-impact reviews</p>
<p>Each category counts distinct apprentices. An apprentice may appear under several markers.</p>
<ul>@forelse($summary as $code => $total)
<li>{{ $code }} · {{ $codes[$code] ?? 'Unknown marker' }} · {{ $total }}</li>
@empty<li>No open classified concerns in scope.</li>@endforelse</ul>
<a class="button" href="{{ route('support.worklist', ['view'=>'needs-review']) }}">Open the review list</a>
</section>
@endsection

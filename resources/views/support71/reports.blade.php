@extends('layout')
@section('title', 'Support and Retention Reports')
@section('content')
<header class="page-heading">
    <div>
        <p class="eyebrow">Support and Retention · Scoped snapshot</p>
        <h1>Support and Retention Reports</h1>
        <p class="muted">Built from the same scoped register query. Overall totals count distinct apprentice IDs; overlapping markers are shown in the breakdown below.</p>
    </div>
    <a class="button" href="{{ route('support-register71.index') }}">Back to register</a>
</header>

<section class="panel">
    <h2>Current snapshot ({{ $today }})</h2>
    <dl class="details-grid">
        <div><dt>Distinct apprentices on register</dt><dd>{{ $summary['distinct_apprentices'] }}</dd></div>
        <div><dt>Distinct apprentices needing review</dt><dd>{{ $summary['distinct_needs_review'] }}</dd></div>
        <div><dt>Open shared actions</dt><dd>{{ $summary['open_actions'] }}</dd></div>
    </dl>
</section>

<section class="panel">
    <h2>Open markers by IM code</h2>
    <div class="table-scroll">
        <table>
            <thead><tr><th>Code</th><th>Description</th><th>Open episodes</th><th>Drill-down</th></tr></thead>
            <tbody>
            @forelse($summary['by_code'] as $code => $count)
                <tr>
                    <td><strong>{{ $code }}</strong></td>
                    <td>{{ $codes[$code] ?? 'Uncoded routine support' }}</td>
                    <td>{{ $count }}</td>
                    <td><a class="button" href="{{ route('support-register71.index', ['view' => 'all', 'code' => $code === 'UNCODED' ? '' : $code]) }}">View matching apprentices</a></td>
                </tr>
            @empty
                <tr><td colspan="4">No open markers in your authorised scope.</td></tr>
            @endforelse
            </tbody>
        </table>
    </div>
</section>
@endsection

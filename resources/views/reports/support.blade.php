@extends('layout')
@section('title','Support and Retention report')
@section('content')
<header class="page-heading">
    <div><h1>Support and Retention report</h1><p>Reviews due by {{ \Carbon\Carbon::parse($by)->format('j M Y') }} · authorised apprentices only</p></div>
    <a class="button" href="{{ route('reports.index') }}">Reports</a>
</header>
<details class="panel" @if(array_filter($filters)) open @endif>
    <summary>Filter by group, tutor or review date</summary>
    <form method="get" action="{{ route('support.report') }}" class="search">
        @foreach(['group' => ['Tutor group', $groupOptions], 'tutor' => ['Main tutor', $tutorOptions]] as $key => [$label, $options])
            <div><label for="support-report-{{ $key }}">{{ $label }}</label>
                <select id="support-report-{{ $key }}" name="{{ $key }}">
                    <option value="">All authorised</option>
                    @if(isset($filters[$key]) && !$options->has((int) $filters[$key]))
                        <option value="{{ $filters[$key] }}" selected>Selection unavailable</option>
                    @endif
                    @foreach($options as $id => $name)
                        <option value="{{ $id }}" @selected((int) ($filters[$key] ?? 0) === $id)>{{ $name }}</option>
                    @endforeach
                </select>
            </div>
        @endforeach
        <div><label for="support-report-by">Review by</label><input id="support-report-by" type="date" name="by" value="{{ $filters['by'] ?? '' }}"></div>
        <button>Apply filters</button>
        @if(array_filter($filters))<a href="{{ route('support.report') }}">Clear filters</a>@endif
    </form>
</details>
@include('reports.headline', [
    'title' => 'Who needs attention',
    'basis' => 'Counts are by apprentice. The same person may appear in several rows.',
    'items' => [
        ['Current support and retention caseload', $registerCount],
        ['Need a review or marker check', $registerCount ? $summary['needs-review'] : null, 'exceptions'],
        ['Overdue marker review', $registerCount ? $summary['overdue'] : null, 'exceptions'],
        ['First marker review', $registerCount ? $summary['first-review'] : null, 'exceptions'],
        ['Overdue support action', $registerCount ? $summary['overdue-actions'] : null, 'exceptions'],
        ['Have an active support plan', $activeSupport->count()],
    ],
])
<nav class="toolbar" aria-label="Open follow-up">
    <a class="button primary" href="{{ route('support.worklist', [...$filters, 'view' => 'needs-review']) }}">Open meeting list</a>
    <a class="button" href="{{ route('support.worklist', [...$filters, 'view' => 'overdue-actions']) }}">Open overdue actions</a>
</nav>
<section class="panel">
    <h2>Open markers</h2>
    <p>Each row counts apprentices with that marker once. Markers awaiting a tracking record are included.</p>
    @if($markers->isEmpty())
        <p>No open classified markers in this selection.</p>
    @else
        <div class="table-scroll" role="region" aria-label="Open marker counts" tabindex="0">
            <table>
                <thead><tr><th scope="col">Marker</th><th scope="col">Apprentices</th><th scope="col">Follow-up</th></tr></thead>
                <tbody>@foreach($markers as $code => $count)
                    <tr><th scope="row">{{ $code }} · {{ $codes[$code] ?? 'Code needs checking' }}</th>
                        <td>{{ $count }}</td>
                        <td>@if(isset($codes[$code]))
                            <a href="{{ route('support.worklist', [...$filters, 'view' => 'needs-review', 'marker' => $code]) }}">Reviews for {{ $code }}</a>
                        @endif</td>
                    </tr>
                @endforeach</tbody>
            </table>
        </div>
    @endif
</section>
@include('reports.headline', [
    'title' => 'Support delivered and reviewed',
    'basis' => 'Recorded support-plan activity and reviews from '.\Carbon\Carbon::parse($start)->format('j M Y').' to '.\Carbon\Carbon::parse($end)->format('j M Y').'.',
    'items' => [
        ['Support activities recorded', $delivery['activities']],
        ['Apprentices with a recorded activity', $delivery['apprentices']],
        ['Support-plan reviews recorded', $delivery['reviews']],
    ],
])
<details class="panel">
    <summary>Recorded decisions at support-plan reviews</summary>
    @if($delivery['reviews'])
        <dl class="details-grid">@foreach($delivery['decisions'] as $label => $count)
            <div><dt>{{ $label }}</dt><dd>{{ $count }}</dd></div>
        @endforeach</dl>
    @else
        <p>No dated support-plan reviews in this period.</p>
    @endif
    <p>The counts describe recorded work and decisions. Read the evidence in each private support record when assessing impact.</p>
</details>
@endsection

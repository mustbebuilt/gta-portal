@extends('layout')
@section('title','Historical staff comparisons')
@section('content')
<h1>Historical staff comparisons</h1>@include('learning.errors')
<p>Compare outcomes for separately reviewed planned-end cohorts, using evidence for the tutor responsible at each recorded planned end. Current tutor assignments are never used as historical proof. These are descriptive internal figures, not staff rankings or an official achievement return.</p>
<p><a href="{{ route('cohort-insights.index') }}">Current apprentice progress and forecasts</a></p>
@include('reports.headline', [
    'title' => 'Cohorts available for comparison',
    'basis' => 'Saved cohort status. Analysis uses the approved planned-end cohorts.',
    'items' => [
        ["Approved cohorts", $cohortCounts->get('Approved', 0)],
        ["Draft cohorts for review", $cohortCounts->get('Draft', 0), 'exceptions'],
        ["Rejected or withdrawn", $cohortCounts->get('Rejected', 0) + $cohortCounts->get('Withdrawn', 0)]
    ],
])
<form method="post" action="{{ route('historical-cohorts.preview') }}" class="panel">@csrf<h2>Capture a cohort for review</h2><label for="programme">Programme standard and version</label><select name="programme" id="programme" required><option value="">Choose a programme</option>@foreach($programmes as $p)<option value="{{ $p->id }}">{{ $p->name }} · {{ $p->standard_code }} v{{ $p->version }} · level {{ $p->level }}</option>@endforeach</select><label for="from">Recorded planned ends from</label><input type="date" id="from" name="from" required><label for="to">To</label><input type="date" id="to" name="to" required><label for="grace">Observation window after each recorded planned end (days)</label><input type="number" id="grace" name="grace" value="90" min="0" max="365" required><p>Choose up to366 inclusive days. The observation window is a GTA analysis choice, not a statutory rule or automatic extension. End/achievement events dated after the chosen cut-off are reported separately. Outcomes are the records loaded now, not a reconstruction of what the portal knew years ago.</p><button class="primary">Preview cohort</button></form>
<section class="panel"><h2>Saved cohorts</h2>@forelse($cohorts as $c)<p><a href="{{ route('historical-cohorts.show',$c) }}">{{ \App\Support\HistoricalCohorts::label($c) }} · captured {{ $c->created_at->format('d/m/Y H:i') }}</a></p>@empty<p>No historical cohorts captured.</p>@endforelse{{ $cohorts->links() }}</section>
@if($approved->count()>1)<form method="get" action="{{ route('historical-cohorts.compare') }}" class="panel"><h2>Compare approved cohorts</h2><label for="earlier">Earlier planned-end cohort</label><select id="earlier" name="earlier" required>@foreach($approved as $c)<option value="{{ $c->id }}">{{ \App\Support\HistoricalCohorts::label($c) }} · approved {{ $c->approved_at?->format('d/m/Y') }}</option>@endforeach</select><label for="later">Later planned-end cohort</label><select id="later" name="later" required>@foreach($approved as $c)<option value="{{ $c->id }}">{{ \App\Support\HistoricalCohorts::label($c) }} · approved {{ $c->approved_at?->format('d/m/Y') }}</option>@endforeach</select><p>Use distinct non-overlapping cohorts of the same standard/version/level, the same observation window and comparable interval lengths. Both captured cohorts must be fully mature.</p><button>Compare approved cohorts</button></form>@endif
@endsection

@extends('layout')
@section('title','Support and Retention Register')
@section('content')
<header class="page-heading"><h1>Support and Retention Register</h1><a class="button" href="{{ route('reports.index') }}">Reports</a></header>
@include('learning.errors')
<form method="get" class="panel search">
<label for="support-search">Apprentice name or reference</label><input id="support-search" name="search" value="{{ $search }}">
<label for="review-by">Review by</label><input id="review-by" type="date" name="by" value="{{ $by }}">
<label for="register-marker">Marker</label><select id="register-marker" name="marker">
<option value="">All markers</option>
@foreach($codes as $code => $label)<option value="{{ $code }}" @selected(request('marker') === $code)>{{ $code }} · {{ $label }}</option>@endforeach
</select>
<button name="view" value="all">All · {{ $allCount }}</button>
<button name="view" value="needs-review" @if($view === 'needs-review') aria-current="true" @endif>Needs review · {{ $dueCount }}</button>
</form>
<section class="panel">
@forelse($rows as $row)
@php($person = $row['person'])
<article class="work-row"><div>
<h2>{{ $person->first_name }} {{ $person->last_name }} · {{ $person->reference }}</h2>
<p class="status {{ $row['needs_review'] ? 'red' : 'achieved' }}">{{ $row['needs_review'] ? 'Needs review' : 'No marker due' }}</p>
<p>{{ $row['codes']->implode(' · ') ?: 'No recorded markers' }}</p>
@foreach($row['open'] as $marker)
<p>{{ $marker->code ?? 'Unclassified concern' }} · {{ !$marker->next_review ? 'First review or classification needed' : 'Next review '.$marker->next_review->toDateString() }}</p>
@endforeach
@if($row['unlinked']->isNotEmpty())<p>Set up a review record for {{ $row['unlinked']->implode(', ') }}.</p>@endif
</div><div class="toolbar">
<a class="button" href="{{ route('support.index', $person) }}#marker-reviews">Quick review</a>
<a class="button" href="{{ route('support.index', $person) }}#quick-support-log">Log support</a>
<a class="button" href="{{ route('support.index', $person) }}">Open support record</a>
</div></article>
@empty<p>No apprentices match these filters.</p>@endforelse
{{ $rows->links() }}
</section>
<p><a href="{{ route('support.worklist', ['state'=>'open']) }}">Concern and support plan worklists</a></p>
@endsection

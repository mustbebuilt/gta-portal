@extends('layout')
@section('title','Apprentice record checks')
@section('content')
<header class="page-heading"><div><h1>Apprentice record checks</h1><p>Find missing record links, then open the apprentice to check their current information.</p></div><a class="button" href="{{ route('quick-actions.index') }}">Quick actions</a></header>
@include('learning.errors')
<form class="search" method="get"><div><label for="record-search">Name or apprentice reference</label><input id="record-search" name="search" value="{{ $search }}" maxlength="100"></div><div><label for="record-check">Show</label><select id="record-check" name="check">@foreach(\App\Support\ApprenticeRecordChecks::FILTERS as $key=>$label)<option value="{{ $key }}" @selected($check===$key)>{{ $label }}</option>@endforeach</select></div><button>Find records</button><a class="button" href="{{ route('record-checks.index') }}">Clear filters</a></form>
<p>{{ $records->total() }} matching apprentice record(s) in your access. These filters find missing values only. Open a record to check inactive accounts, changed duties, conflicting dates and source approval.</p>
<section class="panel">@forelse($records as $a)<article class="work-row"><div><h2>{{ $a->first_name }} {{ $a->last_name }}</h2><p>{{ $a->reference }}</p></div><a class="button primary" href="{{ route('record-checks.show',$a) }}">Check record</a></article>@empty<h2>No matching records</h2><p>Clear filters to see your records. This does not establish that every record is complete or compliant.</p>@endforelse
{{ $records->links() }}</section>
@endsection

@extends('layout')
@section('title','Maths and English caseload')
@section('content')
<header class="page-heading"><div><h1>Maths and English</h1><p>Follow up recorded qualification targets and topic evidence within your current subject permissions.</p></div></header>
<section class="panel"><h2>Find apprentices</h2>
<form class="search" method="get"><div><label for="em-search">Name or reference</label><input id="em-search" name="search" maxlength="100" value="{{ $search }}"></div><div><label for="em-subject">Subject</label><select id="em-subject" name="subject">@foreach(['all'=>'All permitted subjects','maths'=>'Maths','english'=>'English'] as $key=>$label)<option value="{{ $key }}" @selected($selectedSubject===$key)>{{ $label }}</option>@endforeach</select></div><div><label for="em-view">Follow-up</label><select id="em-view" name="view">@foreach(['all'=>'All apprentices','overdue'=>'Qualification overdue','today'=>'Qualification due today','checks'=>'Qualification date checks','awaiting'=>'Topic evidence awaiting review','referred'=>'Topic evidence referred','unrecorded'=>'No qualification record in a permitted subject'] as $key=>$label)<option value="{{ $key }}" @selected($selectedView===$key)>{{ $label }} ({{ $followupCounts[$key] }})</option>@endforeach</select></div><button>Apply filters</button><a href="{{ route('em.caseload') }}">Clear filters</a></form>
<p class="muted">Counts are apprentices within the search and subject selection, before the follow-up filter. Categories overlap. Targets use each qualification's custom start + twelve months. These are recorded targets, not whole-programme compliance checks; programme status is not used to infer current delivery.</p></section>
<section class="panel"><h2>Assigned apprentices ({{ $followupRows->total() }})</h2>
@forelse($followupRows as $row)@php($a=$row['apprentice'])
<article class="work-row"><div><h3>{{ $a->first_name }} {{ $a->last_name }}</h3><p>{{ $a->reference }}</p>
@foreach($row['subjects'] as $subject=>$summary)
<div><h4>{{ ucfirst($subject) }}</h4><p>{{ $summary['qualifications'] }} qualification records · {{ $summary['overdue'] }} overdue · {{ $summary['today'] }} due today · {{ $summary['checks'] }} date checks.</p>
@if($summary['unrecorded'])<p>No qualification record: check the source. This does not mean achieved or exempt.</p>@endif
<p>{{ $summary['completed_topics'] }} of {{ $summary['planned_topics'] }} topics with planned hours recorded completed · {{ number_format($summary['planned_hours'],2) }} planned hours, not achieved hours.</p>
<p>{{ $summary['awaiting'] }} topic submissions awaiting review · {{ $summary['referred'] }} referred topics.</p>
@if($summary['overdue'])<a class="button" href="{{ route('em.index',[$a,'subject'=>$subject,'show'=>'overdue']) }}">{{ ucfirst($subject) }} overdue qualifications</a>@endif
@if($summary['today'])<a class="button" href="{{ route('em.index',[$a,'subject'=>$subject,'show'=>'today']) }}">{{ ucfirst($subject) }} qualifications due today</a>@endif
@if($summary['checks'])<a class="button" href="{{ route('em.index',[$a,'subject'=>$subject,'show'=>'checks']) }}">{{ ucfirst($subject) }} date checks</a>@endif
@if($summary['awaiting'])<a class="button" href="{{ route('em.index',[$a,'subject'=>$subject,'topics'=>'awaiting']) }}">{{ ucfirst($subject) }} evidence awaiting review</a>@endif
@if($summary['referred'])<a class="button" href="{{ route('em.index',[$a,'subject'=>$subject,'topics'=>'referred']) }}">{{ ucfirst($subject) }} referred evidence</a>@endif
</div>@endforeach</div><a class="button" href="{{ route('em.index',$a) }}">Open Maths and English</a></article>
@empty<p>No matching apprentices in your permitted subjects. An empty list does not establish qualification achievement or completed training.</p>@endforelse
<div class="pagination">{{ $followupRows->links() }}</div></section>
@endsection

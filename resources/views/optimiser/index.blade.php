@extends('layout')
@section('title','Improve the timetable')
@section('content')
<h1>Improve the timetable</h1>@include('learning.errors')
<p>Check several future plans together to reduce gaps between activities. Teaching and repeated plans can be included when every occurrence is future, current and unlinked. Dates, duration, staff, group, room and site stay the same. You review a proposal before anything moves.</p>
<p><a href="{{ route('centre-planning.index') }}">Back to centre timetable</a> · <a href="{{ route('work-moves.index') }}">Move one non-teaching activity to another date</a></p>
<form method="get" class="panel"><label for="from">From</label><input id="from" type="date" name="from" value="{{ $from }}" required><label for="to">To</label><input id="to" type="date" name="to" value="{{ $to }}" required><button>Show plans</button></form>
@if($more)<p class="notice">More than200 plans match. Narrow the period; this list is limited. The optimiser still checks all busy sources in the chosen period and refuses overlarge or incomplete sources.</p>@endif
<form method="post" action="{{ route('optimiser.preview') }}" class="panel">@csrf<input type="hidden" name="from" value="{{ $from }}"><input type="hidden" name="to" value="{{ $to }}"><h2>Choose up to eight whole plans</h2><p>Use a period up to93 days. One repeated plan includes all its occurrences; a partly delivered series, linked register or cover history needs its existing workflow.</p>
@forelse($items as $i)<article class="work-row"><div>@if($i['eligible'])<label><input type="checkbox" name="plans[]" value="{{ $i['id'] }}">{{ $i['title'] }}</label><p>{{ $i['kind'] }} · first date {{ $i['date'] }} · {{ $i['start'] }}–{{ $i['end'] }} · {{ $i['occurrences'] }} occurrence(s)</p>@else<strong>{{ $i['title'] }}</strong><p>{{ $i['reason'] }}</p>@endif<a href="{{ route('centre-planning.show',$i['id']) }}">Open original plan</a></div></article>@empty<p>No Published plans match this period.</p>@endforelse
<button class="primary">Check a timetable proposal</button></form>
<section class="panel"><h2>Your saved proposals</h2>@forelse($runs as $run)<p><a href="{{ route('optimiser.show',$run) }}">{{ $run->state }} · {{ $run->created_at->format('d/m/Y H:i') }}</a></p>@empty<p>No proposals saved.</p>@endforelse{{ $runs->links() }}</section>
@endsection

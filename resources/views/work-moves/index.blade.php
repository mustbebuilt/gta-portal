@extends('layout')
@section('title','Reschedule planned work')
@section('content')
<h1>Reschedule planned work</h1><p>Find another checked slot for a single future marking, preparation, administration, learner-support or staff-development activity. Its duration, staff member and site stay the same. Teaching, travel, review appointments and repeated series use their original workflows.</p><p><a href="{{ route('centre-planning.index') }}">Back to centre timetable</a></p>
@if($more)<p class="notice">Only the latest200 published plans were checked. Open an older plan from the original planning list if needed.</p>@endif
<section class="panel">@forelse($plans as $p)<article class="work-row"><div><h2>{{ $p->source['data']['args']['title'] }}</h2><p>{{ $p->source['data']['args']['date'] }} · {{ $p->source['data']['args']['start'] }}–{{ $p->source['data']['args']['end'] }} · {{ $p->source['data']['args']['site'] }}</p></div><a class="button" href="{{ route('work-moves.show',$p) }}">Check alternative slots</a></article>@empty<p>No eligible single future activities in this list. This does not mean every timetable issue is resolved.</p>@endforelse</section>
@endsection

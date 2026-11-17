@extends('layout')
@section('title','Retention administration')
@section('content')<h1>Retention administration</h1>
<p><a href="{{ route('retention.admin',['show'=>'quality']) }}">Missing data and entry confirmations</a> · <a href="{{ route('retention.admin',['show'=>'followup']) }}">3, 6 and 12-month destination checks</a> · <a href="{{ route('retention.report') }}">Retention analysis</a> · <a href="{{ route('destinations.index') }}">Existing destination contacts</a></p>
<p>Administrative actions are separate from live support concerns. Due and overdue dates below are portal reminders. No email or text is sent.</p>
<form method="get"><input type="hidden" name="show" value="{{ $show }}"><label><input name="mine" type="checkbox" value="1" @checked(request('mine')==='1')> My assigned actions</label><button>Filter</button></form>@include('learning.errors')
@foreach($items as $item)
@php($a=$show==='quality'?$item:$people->get($item->apprentice_id))
<article class="panel"><h2>{{ $a->reference }} · {{ $a->first_name }} {{ $a->last_name }}</h2>
@if($show==='quality')
@php($assignment=$assignments->get($a->id))<p>{{ implode(' · ',$gaps[$a->id]) }}</p><p><a href="{{ route('retention.entry',$a) }}">Complete / check entry information</a></p>
<p>Due {{ $assignment?->due_on?->format('d M Y')??'Unassigned — assign a deadline' }} @if($assignment?->due_on && $assignment->due_on->lt(today()))· Overdue @endif</p>
<form method="post" action="{{ route('retention.quality.assign',$a) }}">@csrf<input name="version" type="hidden" value="{{ $assignment?->version??0 }}"><label for="quality-owner-{{ $a->id }}">Responsible administrator</label><select id="quality-owner-{{ $a->id }}" name="owner_id" required><option value="">Choose owner</option>@foreach($owners as $u)<option value="{{ $u->id }}" @selected($assignment?->owner_id===$u->id)>{{ $u->name }}</option>@endforeach</select><label for="quality-due-{{ $a->id }}">Deadline</label><input id="quality-due-{{ $a->id }}" name="due_on" type="date" required value="{{ $assignment?->due_on?->toDateString() }}"><button>Assign data-quality action</button></form>
@else
<p>{{ $item->months }}-month check · Exit {{ $item->exit_on->format('d M Y') }} · Due {{ ($item->retry_on??$item->due_on)->format('d M Y') }} @if(($item->retry_on??$item->due_on)->lt(today()))· Overdue @endif · {{ $item->state }}</p>
<form method="post" action="{{ route('retention.milestone.assign',$item) }}">@csrf<input name="version" type="hidden" value="{{ $item->version }}"><label for="followup-owner-{{ $item->id }}">Responsible administrator</label><select id="followup-owner-{{ $item->id }}" name="owner_id" required><option value="">Unassigned — choose owner</option>@foreach($owners as $u)<option value="{{ $u->id }}" @selected($item->owner_id===$u->id)>{{ $u->name }}</option>@endforeach</select><button>Assign follow-up</button></form><p><a href="{{ route('destinations.show',$a) }}">Open guided destination check</a></p>
@endif</article>
@endforeach
@if($items->isEmpty())<p>No actions in this view.</p>@endif{{ $items->links() }}@endsection

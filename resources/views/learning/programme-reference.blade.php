@extends('layout')
@section('title','Programme task sources')
@section('content')
<header class="page-heading"><div><p class="eyebrow">{{ $apprentice->reference }} · Task source reference</p><h1>{{ $catalogue['name']??'Programme task sources' }}</h1></div><a class="button" href="{{ route('learning.index',$apprentice) }}">Back to training tasks</a></header>
<section class="panel"><h2>Task sources and assessment</h2><p>Practical and reflection questions come from the programme's supplied PDF pack. Task 1 records a paper knowledge result; Task 2 uses the shared workplace job card. The source PDFs remain available from each task.</p><p>These task records do not approve individual training hours, block dates, KSB coverage, RPL or gateway readiness. A controlled programme-specific training plan and KSB mapping remain required.</p>@if($catalogue)<p>Configured programme link: {{ $catalogue['code'] }} v{{ $catalogue['version'] }}.</p>@else<p>No catalogue is loaded for the current linked programme/version.</p>@endif</section>
@forelse($tasks->groupBy('block') as $block=>$items)<section class="panel"><h2>Block {{ $block }}</h2>@foreach($items as $task)<p><a href="{{ route('tasks.show',$task) }}">{{ $task->title }}</a> · {{ $task->status }}</p>@endforeach</section>@empty<section class="panel"><p>No tasks have been assigned.</p></section>@endforelse
@endsection

@extends('layout')
@section('title','Teaching instructions')
@section('content')
<header class="page-heading"><div><p class="eyebrow">{{ $apprentice->first_name }} {{ $apprentice->last_name }}</p><h1>Approved teaching instructions</h1><p>Practical instructions released to you. Private case notes and marker details are excluded.</p></div></header>
@include('learning.errors')
@forelse($instructions as $i)<section class="panel"><h2>Instruction {{ $i->id }}</h2><p class="recorded-answer">{{ $i->release['text'] }}</p><p>Approved {{ $i->release['approved_at'] }}</p>@if(isset(($i->acknowledgements??[])[(string)auth()->id()]))<p>Read acknowledged {{ $i->acknowledgements[(string)auth()->id()] }}</p>@else<form method="post" action="{{ route('support.instruction.ack',$i) }}">@csrf<input type="hidden" name="version" value="{{ $i->version }}"><label class="check"><input type="checkbox" name="confirmed" value="1" required>I have read the instruction.</label><button>Acknowledge instruction</button></form>@endif</section>@empty<section class="panel"><h2>No instructions released to you</h2><p>This does not disclose whether a private support case exists.</p></section>@endforelse
@endsection
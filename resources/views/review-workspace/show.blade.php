@extends('layout')
@section('title','Review preparation worksheet')
@section('content')
<header class="page-heading"><div><p class="eyebrow">{{ $worksheet['identity']['reference'] }}</p><h1>Review preparation worksheet</h1><p>{{ $worksheet['identity']['first_name'] }} {{ $worksheet['identity']['last_name'] }} · captured {{ $worksheet['captured_at'] }}.</p></div><a class="button" href="{{ route('review-workspace.pdf',[$worksheet['identity']['id'],'from'=>$worksheet['from'],'to'=>$worksheet['to']]) }}">Download preparation PDF</a></header>
@include('learning.errors')
<p>Factual preparation only. The period below is not an actual meeting date. Opening or downloading this worksheet does not save a review, apply notes, approve a judgement or sign anything.</p>
<form method="get" class="search"><label>Training period from <input type="date" name="from" value="{{ $worksheet['from'] }}" required></label><label>Training period to <input type="date" name="to" value="{{ $worksheet['to'] }}" required></label><button>Refresh factual worksheet</button></form>
@foreach(\App\Support\ReviewBriefs::FIELDS as $key=>$label)<section class="panel"><h2>{{ $label }}</h2><p class="recorded-answer">{{ $worksheet['notes'][$key] }}</p></section>@endforeach
<section class="panel"><h2>Next step</h2><p>Check the records with the apprentice and employer, then edit the review in its normal workflow. Private support notes are not included. Current context is not a reconstructed historical return.</p><div class="toolbar"><a class="button primary" href="{{ $worksheet['position']['url'] }}">Continue / start review</a>@if($worksheet['draft_id'])<a class="button" href="{{ route('review-briefs.index',[$worksheet['identity']['id'],$worksheet['draft_id']]) }}">Capture checked proposed notes for this draft</a>@endif<a class="button" href="{{ route('review-workspace.index') }}">Back to preparation list</a></div></section>
@endsection

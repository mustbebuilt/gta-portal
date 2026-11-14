@extends('layout')
@section('title','Reviewed data imports')
@section('content')
<header class="page-heading"><div><h1>Reviewed data imports</h1><p>Preview first. A different administrator/CEO checks and applies each exact batch.</p></div><a class="button" href="{{ route('dashboard.report') }}">Operational report</a></header>
@include('learning.errors')
<div class="toolbar"><a class="button" href="{{ route('imports.create','qualification-results') }}">Import qualification results</a><a class="button" href="{{ route('imports.create','historical-otj') }}">Import historical OTJ</a></div>
<section class="panel"><h2>Saved import batches</h2>@forelse($batches as $b)<article class="work-row"><div><h3>{{ $b->kind==='historical-otj'?'Historical OTJ':'Qualification results' }} · {{ $b->source_name }}</h3><p>{{ $b->state }} · {{ count($b->rows) }} rows · Prepared {{ $b->created_at->format('d M Y H:i') }}</p></div><a class="button" href="{{ route('imports.show',$b) }}">Open {{ $b->state==='Preview'?'preview':'applied record' }}</a></article>@empty<p>No import batches prepared.</p>@endforelse{{ $batches->links() }}</section>
@endsection

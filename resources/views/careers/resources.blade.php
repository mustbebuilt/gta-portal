@extends('layout')
@section('title','Careers resources')
@section('content')
<header class="page-heading"><div><h1>Careers resources</h1><p>Automotive routes, local careers support and useful services.</p><p class="muted">Source catalogue checked {{ $checked }}. External services and availability can change.</p></div></header><div class="dashboard-grid">@foreach($resources as $resource)<section class="panel"><h2>{{ $resource['title'] }}</h2><p>{{ $resource['description'] }}</p><p class="muted">{{ implode(', ',$resource['areas']) }}</p><a class="button" href="{{ $resource['url'] }}" target="_blank" rel="noopener noreferrer">Open website</a></section>@endforeach</div>
@endsection
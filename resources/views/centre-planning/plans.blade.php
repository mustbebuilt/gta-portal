@extends('layout')
@section('title','Planning series')
@section('content')
@include('learning.errors')<h1>Drafts & published series</h1>@include('centre-planning.links')@forelse($plans as $p)<section class="panel"><h2><a href="{{ route('centre-planning.show',$p) }}">{{ \App\Support\CentrePlanning::intact($p)?$p->source['data']['args']['title']:'Evidence needs repair' }}</a></h2><p>{{ $p->state }} · version {{ $p->version }} · updated {{ $p->updated_at }}</p></section>@empty<p>No planning series yet.</p>@endforelse{{ $plans->links() }}
@endsection

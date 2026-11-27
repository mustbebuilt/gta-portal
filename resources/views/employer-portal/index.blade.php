@extends('layout')
@section('title','My employer actions')
@section('content')
<header class="page-heading"><h1>My employer actions</h1></header>
@if($nextSignature)
    <section class="panel">
        <h2>Progress review ready to sign</h2>
        <p>Respond by {{ $nextSignature->expires_on->format('j M Y') }}.</p>
        <a class="button primary" href="{{ route('electronic-reviews.show', $nextSignature) }}">Read and sign the review</a>
    </section>
@endif
<section class="panel">
    <h2>Requests to answer</h2>
    @if($pendingCount)
        <p>{{ $pendingCount }} {{ \Illuminate\Support\Str::plural('request', $pendingCount) }} awaiting a reply.</p>
    @else
        <p>No requests awaiting a reply.</p>
    @endif
    @foreach($actions->where('state', 'Pending') as $request)
        @include('employer-portal.request-row', ['request' => $request, 'reply' => true])
    @endforeach
</section>
@if($actions->where('state', '!=', 'Pending')->isNotEmpty())
    <details class="panel" @if($actions->currentPage() > 1 && $actions->where('state', 'Pending')->isEmpty()) open @endif>
        <summary>Earlier requests and replies</summary>
        @foreach($actions->where('state', '!=', 'Pending') as $request)
            @include('employer-portal.request-row', ['request' => $request, 'reply' => false])
        @endforeach
    </details>
@elseif($actions->isEmpty())
    <p>No current requests. If you expected one, ask GTA to check your named access.</p>
@endif
{{ $actions->links() }}
<div class="toolbar">
    <a class="button" href="{{ route('electronic-reviews.index', ['filter'=>'mine']) }}">Reviews waiting for my sign-off</a>
    <a href="{{ route('workplace-support.index') }}">Workplace support summaries shared with me</a>
</div>
@include('employer-resources65.dashboard-link')
@endsection

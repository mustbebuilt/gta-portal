@extends('layout')
@section('title','My employer actions')
@section('content')
<header class="page-heading"><h1>My employer actions</h1></header>
<section class="panel">
    <h2>Requests to answer</h2>
    <p>{{ $pendingCount }} {{ \Illuminate\Support\Str::plural('request', $pendingCount) }} awaiting a reply.</p>
    @foreach($actions->where('state', 'Pending') as $request)
        @include('employer-portal.request-row', ['request' => $request, 'reply' => true])
    @endforeach
</section>
@if($actions->where('state', '!=', 'Pending')->isNotEmpty())
    <details class="panel" @if(!$pendingCount || $actions->where('state', 'Pending')->isEmpty()) open @endif>
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

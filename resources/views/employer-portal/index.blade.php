@extends('layout')
@section('title','My employer actions')
@section('content')
<header class="page-heading"><h1>My employer actions</h1><div class="toolbar"><a class="button" href="{{ route('electronic-reviews.index',['filter'=>'mine']) }}">Reviews waiting for my sign-off</a><a class="button" href="{{ route('workplace-support.index') }}">Workplace support summaries shared with me</a></div></header>
@include('employer-resources65.dashboard-link')
<p>Requests GTA has checked and shared with your named login. Your authority is checked for each apprentice. Replies do not sign a review or approve training hours.</p>@forelse($actions as $x)<section class="panel"><h2>{{ $x->title }}</h2><p>{{ $x->state }} · Requested by {{ $x->due_date?->format('d M Y')??'No date set' }}</p><a class="button {{ $loop->first?'primary':'' }}" href="{{ route('employer-actions.show',$x) }}">Open request and reply</a></section>@empty<section class="panel"><h2>No current requests</h2><p>If you expected a request, ask GTA to check your named access and the published request.</p></section>@endforelse
{{ $actions->links() }}@endsection

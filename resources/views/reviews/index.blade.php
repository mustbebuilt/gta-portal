@extends('layout')
@section('title','Progress reviews')
@section('content')
<header class="page-heading"><div><h1>Progress reviews</h1><p>Prepare a saved draft using the original review sections and connected source records.</p></div></header>
<p class="test-notice">Draft workflow only. Whole individual training plans, completed review history, three signatures, employer sharing and final PDF exports are not connected. No review compliance percentage is calculated.</p>
<section class="panel"><h2>Apprentices in your scope</h2>@forelse($apprentices as $a)<article class="work-row"><div><h3>{{ $a->first_name }} {{ $a->last_name }}</h3><p>Mentor: {{ $a->mentor?->name??'Not assigned' }} · Tutor: {{ $a->tutor?->name??'Not assigned' }}</p><p>First-review reference: {{ $a->start_date?->copy()->addDays(70)->format('d M Y')??'Start date not recorded' }}. Completed history must be checked before treating this as a current due date.</p></div><a class="button" href="{{ route('reviews.create',$a) }}">Open review drafts</a></article>@empty<p>No apprentices in your current scope.</p>@endforelse</section>
@endsection
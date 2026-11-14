@extends('layout')
@section('title','Questionnaire link setup')
@section('content')
<header class="page-heading"><div><h1>Questionnaire link setup</h1><p>Read-only configuration status. Martin manages the destinations in the hosting settings.</p></div><a class="button" href="{{ route('questionnaires.index') }}">Questionnaires</a></header>
<p class="test-notice">A configured link has not been verified with the external provider by this portal. An authorised person must check each exact shared responder link, its privacy notice and access settings before enabling it. Do not use a design/edit link or a link with an apprentice's details prefilled.</p>
@foreach($inspection['links'] as $row)<section class="panel"><h2>{{ $row['title'] }}</h2><p>{{ $row['status'] }}</p>@if($row['host'])<p>Host: {{ $row['host'] }}</p>@endif</section>@endforeach
<section class="panel"><h2>Separate from receipt tracking</h2><p>No answers are imported, no receipt is created by opening a link, and no check or concern is closed. Inclusion Team permissions on the existing follow-up records remain in effect.</p><p>Changing a destination requires a new review of that exact URL. Until then its learner button is unavailable.</p></section>
@endsection

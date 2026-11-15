@extends('layout')
@section('title','Check apprentice record')
@section('content')
<nav aria-label="Page location"><a href="{{ route('record-checks.index') }}">Apprentice record checks</a> / Check record</nav>
<header class="page-heading"><div><p class="eyebrow">{{ $identity['reference'] }}</p><h1>{{ $identity['first_name'] }} {{ $identity['last_name'] }}</h1><p>Check the record before taking the next action.</p></div><a class="button" href="{{ route('quick-actions.person',$identity['id']) }}">Other actions for this apprentice</a></header>
<p>These prompts describe current saved records. They are not Gateway, qualification, funding or sign-in approval. No changes are saved by opening this page.</p>
<div class="action-grid">@foreach($checks as $row)<section class="panel action-card" data-record-check="{{ $row['key'] }}"><h2>{{ $row['label'] }}</h2><p><strong>{{ ['recorded'=>'Recorded — check source if needed','check'=>'Needs checking','check-if-needed'=>'Check assessed requirement'][$row['position']] }}</strong></p><p>{{ $row['text'] }}</p>@if($row['url'])<a class="button" href="{{ $row['url'] }}">Open source record</a>@else<p>Ask an administrator to check or change this record.</p>@endif</section>@endforeach</div>
@endsection

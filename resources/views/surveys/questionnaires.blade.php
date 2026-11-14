@extends('layout')
@section('title','Questionnaires')
@section('content')
<header class="page-heading"><div><p class="eyebrow">Apprentice support</p><h1>Questionnaires</h1><p>Choose the questionnaire you have been asked to complete.</p></div></header>
<p class="test-notice">These questionnaires open on an external service in a new tab. Read the privacy information on that form before answering. Opening a link does not record submission or completion in this portal.</p>
@foreach($cards as $kind=>$card)
<section class="panel"><h2>{{ $card['title'] }}</h2>
@if($kind==='induction')<p>Complete during induction.</p>@elseif($kind==='sixweek')<p>Complete six weeks after your first learning day, when requested by the Inclusion Team.</p>@else<p>Voluntary and available whenever you want to share how you are getting on. There is no overdue flag.</p>@endif
@if($card['ready'])<p>Opens on {{ $card['host'] }}.</p><a class="button primary" href="{{ $card['url'] }}" target="_blank" rel="noopener noreferrer" referrerpolicy="no-referrer">Open {{ $card['title'] }} <span>(new tab)</span></a>
@else<p role="status">Currently unavailable. Ask the Inclusion Team for the correct questionnaire link.</p>@endif
</section>
@endforeach
<section class="panel"><h2>After submitting</h2><p>The Inclusion Team checks receipt separately. This page does not show your answers, receipt history or follow-up notes. If you need help now, speak directly to GTA staff; a questionnaire is not an urgent support channel.</p></section>
@endsection

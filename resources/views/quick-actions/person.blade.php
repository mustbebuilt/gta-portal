@extends('layout')
@section('title','Apprentice actions')
@section('content')
<nav aria-label="Page location"><a href="{{ route('quick-actions.index') }}">Quick actions</a> / Apprentice actions</nav>
<header class="page-heading"><div><p class="eyebrow">{{ $identity['reference'] }}</p><h1>{{ $identity['first_name'] }} {{ $identity['last_name'] }}</h1><p>Choose your next action for this apprentice.</p></div></header>
@foreach(collect($cards)->groupBy('area') as $area=>$items)<section class="panel"><h2>{{ $area }}</h2><div class="action-grid">@foreach($items as $card)<article class="action-card"><h3><a href="{{ $card['url'] }}">{{ $card['title'] }}</a></h3><p>{{ $card['body'] }}</p></article>@endforeach</div></section>@endforeach
<p>Only actions in your current access are shown. Open the original record to check its latest information before saving changes.</p>
@endsection

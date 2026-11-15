@extends('layout')
@section('title','Quick actions')
@section('content')
<header class="page-heading"><div><h1>What would you like to do?</h1><p>Choose a task. If it relates to an apprentice, find their record next.</p></div></header>
@include('learning.errors')
@if($task)
<section class="panel" id="find-apprentice"><p><a href="{{ route('quick-actions.index') }}">Back to all quick actions</a></p><h2>{{ \App\Support\PortalActions::TASKS[$task]['title'] }}</h2><p>{{ \App\Support\PortalActions::TASKS[$task]['body'] }}</p>
<form method="get" class="search"><input type="hidden" name="task" value="{{ $task }}"><div><label for="action-apprentice">Find by name or apprentice reference</label><input id="action-apprentice" name="search" value="{{ $search }}" maxlength="100"></div><button>Find apprentice</button><a class="button" href="{{ route('quick-actions.index',['task'=>$task]) }}">Clear search</a></form><p>{{ $people->total() }} matching apprentice(s) in your current access.</p>
@forelse($people as $a)<article class="work-row"><div><h3>{{ $a->first_name }} {{ $a->last_name }}</h3><p>{{ $a->reference }}</p></div><div class="toolbar"><a class="button primary" href="{{ $destinations[$a->id] }}">Open task</a><a class="button" href="{{ route('quick-actions.person',$a) }}">Other actions for this apprentice</a></div></article>@empty<p>No apprentices match in your current access. Clear the search or ask an administrator to check your assignment. No record has been created.</p>@endforelse
{{ $people->links() }}</section>
@else
<form class="search" method="get"><div><label for="action-search">Find a task or section</label><input id="action-search" name="q" value="{{ $q }}" maxlength="100" placeholder="e.g. support plan, results, evidence"></div><button>Find actions</button><a class="button" href="{{ route('quick-actions.index') }}">Show all my actions</a></form>
@foreach($cards->groupBy('area') as $area=>$items)<section class="panel"><h2>{{ $area }}</h2><div class="action-grid">@foreach($items as $key=>$card)<article class="action-card"><h3><a href="{{ route('quick-actions.index',['task'=>$key]) }}#find-apprentice">{{ $card['title'] }}</a></h3><p>{{ $card['body'] }}</p><p class="muted">Choose apprentice next</p></article>@endforeach</div></section>@endforeach
@if($globals->isNotEmpty())<section class="panel"><h2>Your portal</h2><div class="action-grid">@foreach($globals as $card)<article class="action-card"><h3><a href="{{ $card['url'] }}">{{ $card['title'] }}</a></h3><p>{{ $card['body'] }}</p></article>@endforeach</div></section>@endif
@if($cards->isEmpty()&&$globals->isEmpty())<section class="panel"><h2>No matching actions</h2><p>Try a shorter search or show all your actions. Your current role and assignments determine what appears.</p></section>@endif
@endif
@endsection

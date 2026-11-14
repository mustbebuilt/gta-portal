@extends('layout')
@section('title','Management reporting packs')
@section('content')
<header class="page-heading"><div><h1>Management reporting packs</h1><p>Review current records and save a fixed operational copy for later comparison.</p></div><a class="button" href="{{ route('dashboard.report') }}">Live operational report</a></header>
@include('learning.errors')
<section class="panel"><form method="get" class="search"><label for="pack-group">Group scope</label><select id="pack-group" name="group"><option value="">All apprentices</option>@foreach($groups as $group)<option value="{{ $group->id }}" @selected((string)request('group')===(string)$group->id)>{{ $group->name }}</option>@endforeach</select><button>Refresh preview</button></form><form method="post" action="{{ route('management.capture') }}">@csrf<input type="hidden" name="group" value="{{ request('group') }}"><label for="pack-title">Pack title</label><input id="pack-title" name="title" required value="Operational management pack {{ today()->format('d M Y') }}"><label class="check"><input type="checkbox" name="confirmed" value="1" required>Capture the selected current scope. I understand this records the position at save time, not a historical or compliance judgement.</label><button>Save fixed management pack</button></form></section>
<section class="panel"><h2>Open current records needing checks</h2><div class="toolbar">@foreach($snapshot['checks'] as $check)@if($check['issues'])<a class="button" href="{{ route('apprentices.show',$check['apprentice_id']) }}">{{ $check['reference'] }} · {{ count($check['issues']) }} checks</a>@endif @endforeach</div></section>
<section class="panel">@include('management.content')</section>
<section class="panel"><h2>Saved packs</h2>@forelse($packs as $pack)<p><a href="{{ route('management.show',$pack) }}">{{ $pack->title }}</a> · {{ $pack->created_at->format('d M Y H:i') }}</p>@empty<p>No saved packs.</p>@endforelse{{ $packs->links() }}</section>
@endsection

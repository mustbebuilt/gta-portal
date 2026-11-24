@extends('layout')
@section('title','Apprentice setup')
@section('content')
<header class="page-heading"><div><p class="eyebrow">Administration</p><h1>Add apprentice</h1><p>Save a draft and return later.</p></div></header>
<form method="post" action="{{route('setup68.create')}}">@csrf<button class="primary">Start apprentice setup</button></form>
<div class="panel"><h2>Saved setups</h2><table><thead><tr><th>Apprentice</th><th>Status</th><th>Last saved</th><th>Action</th></tr></thead><tbody>@forelse($drafts as $d)<tr><td>{{$d->payload['details']['first_name']??'New'}} {{$d->payload['details']['last_name']??'apprentice'}}</td><td>{{$d->state}}</td><td>{{$d->updated_at}}</td><td><a href="{{route('setup68.show',[$d,$d->state==='Draft'?($d->payload['step']??1):7])}}">{{$d->state==='Draft'?'Resume':'Open'}}</a></td></tr>@empty<tr><td colspan="4">No setups saved yet.</td></tr>@endforelse</tbody></table>{{$drafts->links()}}</div>
@endsection

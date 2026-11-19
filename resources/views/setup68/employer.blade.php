@extends('layout')
@section('title','Add employer')
@section('content')
<link rel="stylesheet" href="{{asset('assets/setup68.css')}}">
<div class="setup68"><header class="page-heading"><div><h1>Add employer</h1><p>Search first. Reuse the existing record where it matches.</p></div></header>
@include('learning.errors')
<section class="panel"><form method="get"><label for="find-employer">Employer name or reference</label><input id="find-employer" name="search" value="{{request('search')}}" maxlength="100"><button>Check existing employers</button></form>@foreach($matches as $e)<p><a href="{{route('directories.show',['employers',$e])}}">{{$e->reference}} — {{$e->name}}</a> · {{$e->active?'Active':'Inactive; review before linking'}}</p>@endforeach</section>
<form method="post" action="{{route('employer68.store')}}">@csrf<input type="hidden" name="token" value="{{$token}}"><section class="panel"><h2>New employer details</h2><p>Reference and name are required. Contact details can be completed later.</p>@include('setup68.employer-fields',['employerFields'=>old('data',[])])<button class="primary">Create employer</button></section></form></div>
@endsection

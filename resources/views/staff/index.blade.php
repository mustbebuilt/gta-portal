@extends('layout')
@section('title','Staff development')
@section('content')
<header class="page-heading"><div><h1>Staff development</h1><p>Your development records and the staff you line-manage.</p></div></header>
<section class="panel"><h2>Staff in your scope</h2>@forelse($people as $person)<article class="work-row"><div><h3>{{ $person->name }}</h3><p>{{ ucfirst(str_replace('_',' ',$person->role)) }} · {{ $person->active?'Active':'Inactive' }}</p></div><a class="button" href="{{ route('staff.show',['target'=>$person,'view'=>$person->id===auth()->id()?'employee':'manager']) }}">Open staff record</a></article>@empty<p>No staff records in this scope.</p>@endforelse</section>
@endsection
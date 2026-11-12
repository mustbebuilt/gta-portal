@extends('layout')
@section('title','Maths and English caseload')
@section('content')
<header class="page-heading"><div><h1>Maths and English</h1><p>Open an assigned apprentice. Subject tutors see only their assigned subject.</p></div></header>
<section class="panel"><h2>Assigned apprentices</h2>@forelse($apprentices as $a)<article class="work-row"><div><h3>{{ $a->first_name }} {{ $a->last_name }}</h3><p>{{ $a->reference }}</p></div><a class="button" href="{{ route('em.index',$a) }}">Open Maths and English</a></article>@empty<p>No apprentices are assigned in your current scope.</p>@endforelse<div class="pagination">{{ $apprentices->links() }}</div></section>
@endsection

@extends('layout')
@section('title','Reports')
@section('content')
<header class="page-heading"><h1>Reports</h1></header>
<section class="panel">
<ul>
@foreach($reports as $route => $label)
<li><a href="{{ route($route) }}">{{ $label }}</a></li>
@endforeach
</ul>
</section>
@endsection

@extends('layout')
@section('title','Confirm email address')
@section('content')
<section class="panel login"><h1>Confirm email address</h1><p>Confirm access to the email recorded on your signed-in account. You will be signed out afterwards.</p>@include('learning.errors')
<form method="post" action="{{ route('account.email.verify',$token) }}">@csrf<button class="primary">Confirm email and sign out</button></form></section>
@endsection

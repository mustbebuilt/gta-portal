@extends('layout')
@section('title','Password recovery')
@section('content')
<section class="panel login"><h1>Password recovery</h1>@include('learning.errors')
@if($available)@if(app()->environment('staging'))<p class="notice">Staging recovery pilot: delivery is restricted to configured test recipients. Contact your GTA administrator if you cannot recover your account.</p>@else<p class="notice">Local recovery test: messages go to an internal mail sink. Nothing is delivered to an external inbox.</p>@endif
<form method="post" action="{{ route('password.email') }}">@csrf<label for="recovery-email">Account email address</label><input id="recovery-email" name="email" type="email" autocomplete="username" required><button>Request reset instructions</button></form>
@else<p>Live password recovery is not enabled in this checkpoint. Contact your GTA administrator.</p>@endif
<a href="{{ route('login') }}">Back to sign in</a></section>
@endsection

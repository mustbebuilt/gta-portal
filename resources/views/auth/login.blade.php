@extends('layout')
@section('title', 'Sign in')
@section('content')
<div class="login-layout"><figure class="login-visual"><img src="{{ asset('assets/vehicle-hero.png') }}" alt="Illustrative vehicle line-up for the GTA portal"><figcaption>Doncaster, Rotherham &amp; District Motor Trades GTA Ltd</figcaption></figure><section class="login panel"><p class="eyebrow">{{ app()->environment('staging')?'GTA staging portal':'GTA local test' }}</p><h1>Sign in</h1><p class="muted">{{ app()->environment('staging')?'Use your authorised staging account. Use fictional apprentice records while testing.':'Use the fictional account created by the local setup command.' }}</p>
@if($errors->any())<div class="errors" role="alert">{{ $errors->first() }}</div>@endif
<form method="post" action="{{ route('login.store') }}">@csrf
<label for="email">Email address</label><input id="email" name="email" type="email" autocomplete="username" value="{{ old('email') }}" required>
<label for="password">Password</label><input id="password" name="password" type="password" autocomplete="current-password" required>
<button class="primary" type="submit">Sign in</button></form><p><a href="{{ route('password.request') }}">Forgot your password?</a></p></section></div>
@endsection

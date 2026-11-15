@extends('layout')
@section('title', 'Sign in')
@section('content')
<div class="portal-signin"><section class="signin-scene" aria-label="GTA Portal welcome"><picture><source media="(max-width:1100px)" srcset="{{ asset('assets/gta-login-scene-small.webp') }}"><img src="{{ asset('assets/gta-login-scene.webp') }}" alt="" width="1585" height="992" fetchpriority="high"></picture><div class="signin-message"><p class="eyebrow">GTA Portal</p><h1>Your future.<br>In motion.</h1><p>Learning, progress and support in one place.</p></div><p class="scene-note">Illustrative artwork</p></section>
<section class="signin-form"><div class="signin-brand"><img src="{{ asset('assets/gta-mark.svg') }}" width="68" height="64" alt="GTA"><span>GTA Portal</span></div><h2>Welcome back</h2><p>Sign in to your GTA Portal account.</p>
@if(app()->environment('staging'))<p class="test-notice">Staging portal — use your authorised test account.</p>@elseif(app()->environment('local','testing'))<p class="test-notice">Local development — use a fictional account.</p>@endif
@if($errors->any())<div class="errors" role="alert">{{ $errors->first() }}</div>@endif
<form method="post" action="{{ route('login.store') }}">@csrf<label for="email">Email address</label><input id="email" name="email" type="email" autocomplete="username" value="{{ old('email') }}" required><label for="password">Password</label><input id="password" name="password" type="password" autocomplete="current-password" required><p class="signin-recovery"><a href="{{ route('password.request') }}">Forgotten your password?</a></p><button class="primary signin-submit" type="submit">Sign in <span aria-hidden="true">→</span></button></form>
<p>Need help signing in? <a href="{{ route('privacy.public') }}#contact">Contact GTA</a></p><nav class="signin-links" aria-label="Sign-in information"><a href="{{ route('privacy.public') }}">Privacy notice</a><a href="{{ route('accessibility.index') }}">Accessibility and help</a></nav></section></div>
@endsection

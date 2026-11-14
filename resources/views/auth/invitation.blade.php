@extends('layout')
@section('title','Set up your staff password')
@section('content')
<section class="panel login"><h1>Set up your staff password</h1><p>Controlled staging invitation. Choose at least 12 characters. Your existing role stays the same; you will sign in separately afterwards.</p>@include('learning.errors')
<form method="post" action="{{ route('onboarding.activate',[$user,$token]) }}">@csrf
<label for="invite-password">New password</label><input id="invite-password" name="password" type="password" autocomplete="new-password" minlength="12" required>
<label for="invite-confirm">Confirm password</label><input id="invite-confirm" name="password_confirmation" type="password" autocomplete="new-password" required><button class="primary">Set password and confirm email</button></form></section>
@endsection

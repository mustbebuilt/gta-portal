@extends('layout')
@section('title','Change password')
@section('content')
<section class="panel login"><h1>Change your password</h1><p>Use at least 12 characters. You will need to sign in again afterwards.</p>@include('learning.errors')
<form method="post" action="{{ route('account.password.update') }}">@csrf
<label for="current-password">Current password</label><input id="current-password" name="current_password" type="password" autocomplete="current-password" required>
<label for="new-password">New password</label><input id="new-password" name="password" type="password" autocomplete="new-password" minlength="12" required>
<label for="confirm-password">Confirm new password</label><input id="confirm-password" name="password_confirmation" type="password" autocomplete="new-password" required>
<button class="primary">Change password and sign out</button></form></section>
@endsection

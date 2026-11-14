@extends('layout')
@section('title','Reset password')
@section('content')
<section class="panel login"><h1>Reset password</h1>@include('learning.errors')
@if($available)<p>Use at least 12 characters. This link can be used once and expires after 60 minutes.</p><form method="post" action="{{ route('password.update') }}">@csrf<input type="hidden" name="token" value="{{ $token }}"><input type="hidden" name="email" value="{{ $email }}"><label for="reset-password">New password</label><input id="reset-password" name="password" type="password" autocomplete="new-password" minlength="12" required><label for="reset-confirmation">Confirm new password</label><input id="reset-confirmation" name="password_confirmation" type="password" autocomplete="new-password" required><button>Reset password</button></form>
@else<p>Password recovery is not enabled here. Contact your GTA administrator.</p>@endif</section>
@endsection

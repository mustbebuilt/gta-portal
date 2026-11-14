@extends('layout')
@section('title','Security code')
@section('content')
<section class="login panel"><h1>Enter your security code</h1><p>Use the six-digit code from your authenticator app, or one unused recovery code. If you just used an app code, wait for the next code.</p>
@include('learning.errors')
<form method="post" action="{{ route('second-factor.verify') }}">@csrf<label for="mfa-code">Authenticator or recovery code</label><input id="mfa-code" name="code" type="text" autocomplete="one-time-code" maxlength="64" required autofocus><button class="primary">Complete sign-in</button></form>
<form method="post" action="{{ route('second-factor.cancel') }}">@csrf<button>Cancel and return to sign-in</button></form>
<p>This step expires after five minutes. Your password alone has not signed you into the portal.</p></section>
@endsection

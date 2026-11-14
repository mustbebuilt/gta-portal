@extends('layout')
@section('title','Two-step sign-in')
@section('content')
<header class="page-heading"><div><h1>Two-step sign-in</h1><p>Add an authenticator check after your password.</p></div></header>
@include('learning.errors')
@if($codes)<section class="panel"><h2>Save these recovery codes now</h2><p>These ten codes are shown once. Keep them somewhere private, separate from your phone. Each code works once, together with your password. Any older recovery codes have been replaced.</p><ul>@foreach($codes as $code)<li><code>{{ $code }}</code></li>@endforeach</ul></section>@endif
@if($factor?->confirmed_at)
<section class="panel"><h2>Two-step sign-in is on</h2><p>Recovery codes remaining: {{ count($factor->recovery_hashes ?? []) }}. Your setup key is no longer displayed.</p><p>To change phones, use your current authenticator or a saved recovery code to disable this setup, then enrol the new phone. New enrolment must be enabled by GTA first.</p>
<form method="post" action="{{ route('second-factor.change') }}">@csrf
<label for="mfa-password">Current password</label><input id="mfa-password" name="current_password" type="password" autocomplete="current-password" required>
<label for="mfa-current-code">Fresh authenticator or unused recovery code</label><input id="mfa-current-code" name="code" type="text" autocomplete="one-time-code" maxlength="64" required>
<label for="mfa-action">Action</label><select id="mfa-action" name="action" required><option value="">Choose</option><option value="regenerate">Replace all recovery codes</option><option value="disable">Turn off two-step sign-in</option></select><button>Update security settings</button></form></section>
@elseif($secret)
<section class="panel"><h2>Add this setup key to your authenticator</h2><p>Choose a time-based account in your authenticator app. Enter this key manually; name the account GTA Portal ({{ auth()->user()->email }}). Use six digits and a thirty-second period if asked. Keep this key private.</p><p><code id="mfa-setup-key">{{ $secret }}</code></p><p>Setup expires fifteen minutes after it starts. Two-step sign-in remains off until a code is confirmed.</p>
<form method="post" action="{{ route('second-factor.change') }}">@csrf<input type="hidden" name="action" value="confirm"><label for="mfa-confirm-password">Current password</label><input id="mfa-confirm-password" type="password" name="current_password" autocomplete="current-password" required><label for="mfa-confirm-code">Six-digit authenticator code</label><input id="mfa-confirm-code" name="code" type="text" inputmode="numeric" pattern="[0-9]{6}" maxlength="6" autocomplete="one-time-code" required><button class="primary">Confirm and turn on</button></form></section>
@else
<section class="panel"><h2>Two-step sign-in is off</h2><p>This is an optional staging pilot. Before starting, have an authenticator app ready and a private place to save your recovery codes. No email or SMS code is sent.</p>
@if($mayEnrol)<form method="post" action="{{ route('second-factor.change') }}">@csrf<input type="hidden" name="action" value="start"><label for="mfa-start-password">Current password</label><input id="mfa-start-password" type="password" name="current_password" autocomplete="current-password" required><button class="primary">Start setup</button></form>@endif</section>
@endif
<p>If both your phone and recovery codes are lost, contact GTA's administrator. Password reset does not remove two-step sign-in. This pilot has no administrator bypass or reset button.</p>
@endsection

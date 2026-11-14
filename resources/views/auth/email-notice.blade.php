@extends('layout')
@section('title','Confirm your email')
@section('content')
<section class="panel login"><h1>Confirm your email</h1>@include('learning.errors')
@if(auth()->user()->email_verified_at)<p>Your email address has been confirmed.</p>
@elseif($available)<p>Controlled staging pilot: send a confirmation link to your recorded test email address. You must sign in to this account before confirming. The link lasts 60 minutes.</p><form method="post" action="{{ route('account.email.send') }}">@csrf<button class="primary">Send verification link</button></form>
@else<p>Email verification is unavailable for this account. Contact your GTA administrator.</p>@endif
<p>Confirmation shows mailbox access. It does not confirm identity, permissions or training evidence.</p></section>
@endsection

@extends('layout')
@section('title','Alert preferences')
@section('content')
<h1>Alert preferences</h1>
@if(!$apprentice)<section class="panel"><p>Your login does not have one unambiguous linked apprentice record. Ask GTA to check the account link before changing alert preferences.</p></section>@else
<section class="panel"><p>Choose how you would like apprentice task and review reminders. We recommend keeping reminders on so you do not miss important dates. You can change your choice or turn reminders off.</p>@include('learning.errors')
<form method="post" action="{{ route('alerts.save') }}">@csrf<input type="hidden" name="alert_revision" value="{{ $apprentice->alert_revision }}">
@include('account.alert-fields',['apprentice'=>$apprentice,'newChoice'=>false])<button class="primary">Save alert preferences</button></form>
<p>These settings record your preference. Email/text delivery must be connected and tested by GTA; texts also need a verified mobile number. Choosing Text or Both does not itself send a message. Switching off does not delete your tasks or reviews.</p></section>
<section class="panel"><h2>Verify your mobile number</h2>
@if($mobileVerification['verified'])<p>Your saved mobile is verified. Changing the number clears this verification. Your alert On/Off choice remains in control.</p>
@elseif(!$mobileVerification['available'])<p>Mobile verification is not available for this account/environment yet. Save Text or Both with reminders On and your correct number; GTA must configure the approved test service before a code can be requested.</p>
@else<p>Request an eight-digit code for your saved mobile. Codes expire in ten minutes, allow five attempts and work only in this signed-in browser session. Requests are limited; an SMS may incur a provider charge.</p>
@if($mobileVerification['state'])<p>Last request: {{ $mobileVerification['state'] }}. Accepted means queued by the provider, not proof it reached your phone.</p>@endif
<form method="post" action="{{ route('alerts.mobile.request') }}">@csrf<button>Send verification code</button></form>
@if($mobileVerification['id'])<form method="post" action="{{ route('alerts.mobile.confirm') }}">@csrf<input type="hidden" name="challenge_id" value="{{ $mobileVerification['id'] }}"><label for="mobile-code">Eight-digit code</label><input id="mobile-code" name="code" type="text" inputmode="numeric" autocomplete="one-time-code" pattern="[0-9]{8}" maxlength="8" required><button>Verify mobile</button></form>@endif
@endif<p>Verification confirms possession of this number at this time. It does not activate task/review reminder sending or replace two-step sign-in.</p></section>@endif
@endsection

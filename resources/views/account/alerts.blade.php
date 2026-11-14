@extends('layout')
@section('title','Alert preferences')
@section('content')
<h1>Alert preferences</h1>
@if(!$apprentice)<section class="panel"><p>Your login does not have one unambiguous linked apprentice record. Ask GTA to check the account link before changing alert preferences.</p></section>@else
<section class="panel"><p>Choose how you would like apprentice task and review reminders. We recommend keeping reminders on so you do not miss important dates. You can change your choice or turn reminders off.</p>@include('learning.errors')
<form method="post" action="{{ route('alerts.save') }}">@csrf<input type="hidden" name="alert_revision" value="{{ $apprentice->alert_revision }}">
@include('account.alert-fields',['apprentice'=>$apprentice,'newChoice'=>false])<button class="primary">Save alert preferences</button></form>
<p>These settings record your preference. Email/text delivery must be connected and tested by GTA; texts also need a verified mobile number. Choosing Text or Both does not itself send a message. Switching off does not delete your tasks or reviews.</p></section>@endif
@endsection

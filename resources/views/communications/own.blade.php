@extends('layout')
@section('title','My reminder status')
@section('content')
<h1>My reminder status</h1><p>Your current choices and saved reminder outcomes. Provider acceptance does not confirm that a text reached your phone or an email reached your inbox.</p><section class="panel">@include('communications.status')<p><a class="button primary" href="{{ route('alerts.index') }}">Change my alert preferences</a><a class="button" href="{{ route('apprentice-reminders.index') }}">Open my reminders</a></p></section>
@endsection

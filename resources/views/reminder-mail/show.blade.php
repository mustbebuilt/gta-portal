@extends('layout')
@section('title','Reminder delivery record')
@section('content')
<header class="page-heading"><div><p class="eyebrow">Reminder mail pilot</p><h1>Delivery #{{ $delivery->id }}</h1><p>{{ \App\Support\ReminderDelivery::LABELS[$delivery->state]??'Needs checks' }}</p></div><a class="button" href="{{ route('reminder-mail.index') }}">Back to preview and history</a></header>
@include('learning.errors')
<section class="panel"><h2>Review position</h2><p>Current recorded account: {{ $recipient?->name??'Unavailable' }} · {{ $recipient?->email??'Unavailable' }}. These current details may differ from the prepared snapshot; dispatch rechecks the exact binding.</p><p>Prepared source items: {{ count($delivery->snapshot['items']??[]) }}. Saved version: {{ $delivery->version }}. Review time: {{ $delivery->approved_at??'Not reviewed' }}.</p><h3>Generic message content</h3><p>Subject: GTA staging portal reminder</p><p>GTA portal reminder</p><p>This is a controlled staging test. Please sign in to the GTA portal to check your current worklists.</p><p>Sign in to GTA: {{ rtrim($delivery->snapshot['origin']??'', '/') }}/login</p><p>Work may have changed since this prompt was prepared. Check the portal for the current position.</p><p>No apprentice details, source counts, attachments or sign-in tokens are included. SMTP acceptance does not prove inbox delivery or completion of any work.</p>
@if(in_array($delivery->state,['prepared','reviewed'],true))
<form method="post" action="{{ route('reminder-mail.transition',$delivery) }}">@csrf<input type="hidden" name="version" value="{{ $delivery->version }}"><label><input type="checkbox" name="confirmed" value="1" required> I have checked this test account and the delivery position.</label>@if($delivery->state==='prepared')<button name="action" value="review">Review for explicit dispatch</button>@endif<button name="action" value="cancel">Cancel before dispatch</button></form>
@endif
@if($delivery->state==='reviewed')<p>Review is valid for 15 minutes. An operator must explicitly dispatch this record using the supplied instructions. No scheduler or page sends it.</p>@endif
@if(in_array($delivery->state,['dispatching','needs_check'],true))<p class="notice">The outcome may be unknown. Do not retry or create a replacement to bypass this record. Check the transport and provider evidence without exposing account details.</p>@endif
</section><section class="panel"><h2>Record history</h2><ol>@foreach($events as $event)<li>{{ $event->created_at }} · {{ $event->event }}</li>@endforeach</ol></section>
@endsection

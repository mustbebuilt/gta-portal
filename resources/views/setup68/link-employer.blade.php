@extends('layout')
@section('title','Confirm employer link')
@section('content')
<header class="page-heading"><h1>{{ $apprentice->employerLabel() ? 'Change employer' : 'Link employer' }}</h1></header>
@include('learning.errors')
<form class="panel" method="post" action="{{ route('employer68.change', $apprentice) }}">
    @csrf
    <input type="hidden" name="version" value="{{ $apprentice->version }}">
    <input type="hidden" name="employer_id" value="{{ $employer->id }}">
    <input type="hidden" name="employer_version" value="{{ $employer->record_version }}">
    <dl class="details-grid">
        <div><dt>Apprentice</dt><dd>{{ $apprentice->first_name }} {{ $apprentice->last_name }} · {{ $apprentice->reference }}</dd></div>
        <div><dt>Current employer</dt><dd>{{ $apprentice->employerLabel() ?? 'No employer linked' }}</dd></div>
        <div><dt>Selected employer</dt><dd>{{ $employer->name }} · {{ $employer->reference }}</dd></div>
        <div><dt>Directory contact</dt><dd>{{ collect([$employer->contact_name, $employer->contact_email, $employer->contact_phone])->filter()->implode(' · ') ?: 'No contact recorded' }}</dd></div>
        <div><dt>GTA Learning &amp; Progress Mentor</dt><dd>{{ $apprentice->mentor?->name ?? 'Not assigned' }}</dd></div>
    </dl>
    @if($apprentice->employer_id !== $employer->id)
        <label for="employer-effective">Effective date</label>
        <input id="employer-effective" name="effective_on" type="date" required max="{{ today()->toDateString() }}" value="{{ old('effective_on', today()->toDateString()) }}">
        <details><summary>Add a reason</summary>
            <label for="employer-reason">Reason (optional)</label>
            <input id="employer-reason" name="reason" maxlength="500" value="{{ old('reason') }}">
        </details>
        <p>Saving revokes previous workplace access for this apprentice. Verify access for the new employer or workplace mentor through <a href="{{ route('employer-grants.index', $apprentice) }}">Employer access</a> after saving.</p>
    @else
        <p>This employer is already linked.</p>
    @endif
    <div class="form-actions">
        @if($apprentice->employer_id !== $employer->id)<button class="primary">Save employer link</button>@endif
        <a class="button" href="{{ route('employer68.show',['apprentice'=>$apprentice->id]) }}">Choose a different employer</a>
        <a href="{{ route('apprentices.show', $apprentice) }}">Back to apprentice</a>
    </div>
</form>
@endsection

@extends('layout')
@section('title','Link employer')
@section('content')
<header class="page-heading"><h1>Link employer</h1></header>
@include('learning.errors')
<form class="panel" method="post" action="{{ route('apprentices.update', $apprentice) }}">
    @csrf @method('put')
    <input type="hidden" name="version" value="{{ old('version', $apprentice->version) }}">
    <input type="hidden" name="reference" value="{{ $apprentice->reference }}">
    <input type="hidden" name="first_name" value="{{ $apprentice->first_name }}">
    <input type="hidden" name="last_name" value="{{ $apprentice->last_name }}">
    <input type="hidden" name="employer_id" value="{{ $employer->id }}">
    <dl class="details-grid">
        <div><dt>Apprentice</dt><dd>{{ $apprentice->first_name }} {{ $apprentice->last_name }} · {{ $apprentice->reference }}</dd></div>
        <div><dt>Current employer</dt><dd>{{ $apprentice->employerLabel() ?? 'No employer linked' }}</dd></div>
        <div><dt>Employer to link</dt><dd>{{ $employer->name }} · {{ $employer->reference }}</dd></div>
    </dl>
    <div class="form-actions">
        <button class="primary">Save employer link</button>
        <a class="button" href="{{ route('employer68.show',['link'=>$employer->id]) }}">Back</a>
    </div>
</form>
@endsection

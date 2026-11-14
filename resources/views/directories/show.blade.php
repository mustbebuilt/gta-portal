@extends('layout')
@section('title', $record->name)
@section('content')
<header class="page-heading"><div><p class="eyebrow">{{ $title }}</p><h1>{{ $record->name }}</h1><p class="muted">{{ $record->active ? 'Active' : 'Inactive' }}</p></div><div class="toolbar"><a class="button" href="{{ route('directories.index',$kind) }}">Back to list</a><a class="button primary" href="{{ route('directories.edit',[$kind,$record->id]) }}">Edit record</a></div></header>
@if($kind === 'staff')
@include('directories.staff-duties')
<p class="notice">Creating a staff directory record sends no message. Use the controlled staging onboarding queue to review the recorded role and email, then explicitly send an eligible test invitation.</p><a class="button" href="{{ route('onboarding.index') }}">Staff onboarding</a>
@endif
<section class="panel"><h2>Recorded details</h2><dl class="details-grid">@foreach($fields as $key=>[$label,$type])<div><dt>{{ str_replace(' *','',$label) }}</dt><dd>{{ $key==='active' ? ($record->active ? 'Active' : 'Inactive') : (is_array($type) ? ($type[(string)$record->$key] ?? 'Not recorded') : ($record->$key ?? 'Not recorded')) }}</dd></div>@endforeach<div><dt>Saved version</dt><dd>{{ $record->record_version }}</dd></div></dl></section>
@endsection

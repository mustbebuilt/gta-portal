@extends('layout')
@section('title', $record->name)
@section('content')
<header class="page-heading"><div><p class="eyebrow">{{ $title }}</p><h1>{{ $record->name }}</h1><p class="muted">{{ $record->active ? 'Active' : 'Inactive' }}</p></div><div class="toolbar"><a class="button" href="{{ route('directories.index',$kind) }}">Back to list</a><a class="button primary" href="{{ route('directories.edit',[$kind,$record->id]) }}">Edit record</a></div></header>
@if($kind === 'staff')
<p class="notice">This is a staff directory record. Creating it does not send an invitation or set up a usable sign-in. Account onboarding and the specialist modules are still to be developed.</p>
@endif
<section class="panel"><h2>Recorded details</h2><dl class="details-grid">@foreach($fields as $key=>[$label,$type])<div><dt>{{ str_replace(' *','',$label) }}</dt><dd>{{ $key==='active' ? ($record->active ? 'Active' : 'Inactive') : (is_array($type) ? ($type[(string)$record->$key] ?? 'Not recorded') : ($record->$key ?? 'Not recorded')) }}</dd></div>@endforeach<div><dt>Saved version</dt><dd>{{ $record->record_version }}</dd></div></dl></section>
@endsection

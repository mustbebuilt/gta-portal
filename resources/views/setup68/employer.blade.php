@extends('layout')
@section('title','Add or link employer')
@section('content')
<link rel="stylesheet" href="{{ asset('assets/setup68.css') }}">
<div class="setup68">
    <header class="page-heading"><div><h1>{{ $apprentice ? ($apprentice->employerLabel() ? 'Change employer' : 'Link employer') : 'Add or link employer' }}</h1>@if($apprentice)<p>{{ $apprentice->first_name }} {{ $apprentice->last_name }} · {{ $apprentice->reference }}</p>@endif</div><a href="{{ route('directories.index','employers') }}">Employer directory</a></header>
    @include('learning.errors')
    @if($employer)
        <form class="panel" method="get" action="{{ route('employer68.show') }}">
            <h2>Link {{ $employer->name }} to an apprentice</h2>
            <input type="hidden" name="link" value="{{ $employer->id }}">
            <input type="hidden" name="link_selection" value="1">
            <label>Apprentice <select name="apprentice" required><option value="">Choose an apprentice</option>
                @foreach($people as $person)<option value="{{ $person->id }}">{{ $person->first_name }} {{ $person->last_name }} · {{ $person->reference }}</option>@endforeach
            </select></label>
            <button class="primary">Continue</button>
        </form>
    @else
        <section class="panel">
            <form method="get" class="search">
                @if($apprentice)<input type="hidden" name="apprentice" value="{{ $apprentice->id }}">@endif
                <div><label for="find-employer">Employer name or reference</label><input id="find-employer" name="search" value="{{ request('search') }}" maxlength="100" required></div>
                <button class="primary">Find employer</button>
            </form>
            @if(request('search') && $matches->isEmpty())<p>No matching employers. You can add a new employer below.</p>@endif
            @foreach($matches as $match)
                <div class="work-row">
                    <p><a href="{{ route('directories.show',['employers',$match]) }}">{{ $match->name }}</a> · {{ $match->reference }} @unless($match->active) · Inactive @endunless</p>
                    @if($match->active)<a class="button" href="{{ route('employer68.show',array_filter(['link' => $match->id, 'apprentice' => $apprentice?->id])) }}">{{ $apprentice ? 'Choose employer' : 'Link apprentice' }}</a>@endif
                </div>
            @endforeach
        </section>
        <details class="panel" @if(old('data') || (request()->filled('search') && $matches->isEmpty())) open @endif>
            <summary>Add a new employer</summary>
            <form method="post" action="{{ route('employer68.store') }}">
                @csrf<input type="hidden" name="token" value="{{ $token }}">
                @if($apprentice)<input type="hidden" name="apprentice" value="{{ $apprentice->id }}">@endif
                @include('setup68.employer-fields',['employerFields'=>old('data',[])])
                <button class="primary">Create employer</button>
            </form>
        </details>
    @endif
</div>
@endsection

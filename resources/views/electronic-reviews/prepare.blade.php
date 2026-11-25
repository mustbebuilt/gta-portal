@extends('layout')
@section('title', 'Review sign-off')
@section('content')
<header class="page-heading">
    <div>
        <p class="eyebrow">{{ $apprentice->first_name }} {{ $apprentice->last_name }} · {{ $apprentice->reference }}</p>
        <h1>Review sign-off</h1>
        <p>{{ $review->state }} · Version {{ $review->version }}</p>
    </div>
    <a class="button" href="{{ route('reviews.edit', [$apprentice, $review]) }}">Back to private review</a>
</header>
@include('electronic-reviews.errors')

@if($review->state !== 'Ready for signatures')
    <p>Finish checking the review and prepare it for signing from the private review.</p>
@elseif($hasSignedCopy)
    <section class="panel">
        <h2>Signed copy on record</h2>
        <a class="button primary" href="{{ route('reviews.signed.form', $review) }}">Open signed-copy record</a>
    </section>
@else
    <section class="panel">
        <h2>Electronic signing</h2>
        @if($authorities->isNotEmpty())
            <p>Choose the named employer representative and the end of the signing window. You’ll check the private preview before releasing it to participants.</p>
            <form method="post" action="{{ route('electronic-reviews.prepare', $review) }}">
                @csrf
                <input type="hidden" name="version" value="{{ $review->version }}">
                <label>Named employer signing authority
                    <select name="authority_id" required>
                        <option value="">Choose a verified employer representative</option>
                        @foreach($authorities as $authority)
                            @php($grant = \App\Models\EmployerApprenticeGrant::find($authority->employer_apprentice_grant_id))
                            <option value="{{ $authority->id }}" @selected(old('authority_id', $authorities->count() === 1 ? $authority->id : null) === $authority->id)>{{ ($grant ? \App\Models\User::find($grant->user_id)?->name : null) ?? 'Account needs checking' }} · Valid until {{ $authority->expires_on->format('d M Y') }}</option>
                        @endforeach
                    </select>
                </label>
                <label>Signing window ends
                    <input type="date" name="expires_on" required min="{{ today()->toDateString() }}" max="{{ today()->addDays(30)->toDateString() }}" value="{{ old('expires_on') }}">
                </label>
                @if(!empty($review->source_snapshot['action_plan']))
                    <details @if(old('action_ids')) open @endif>
                        <summary>Choose actions to include</summary>
                        @foreach($review->source_snapshot['action_plan'] as $action)
                            <label><input type="checkbox" name="action_ids[]" value="{{ $action['id'] }}" @checked(in_array((string) $action['id'], array_map('strval', is_array(old('action_ids')) ? old('action_ids') : []), true))>{{ $action['title'] }} · {{ $action['owner'] }} · {{ $action['due_date'] ?? 'No date' }}</label>
                        @endforeach
                    </details>
                @endif
                <button class="primary">Prepare private preview</button>
            </form>
        @else
            <p>Electronic signing requires a current verified employer representative.</p>
        @endif
        @if(\App\Support\LearningAccess::manager(auth()->user()))
            <p><a href="{{ route('review-authority.index', $apprentice) }}">Manage employer signing authority</a></p>
        @endif
    </section>
    <details class="panel" @if($authorities->isEmpty()) open @endif>
        <summary>Sign on paper</summary>
        <p><a href="{{ route('reviews.pdf', [$apprentice, $review]) }}">Download the review PDF for signing</a></p>
        <p><a href="{{ route('reviews.signed.form', $review) }}">Open signed-copy record</a></p>
    </details>
@endif
@endsection

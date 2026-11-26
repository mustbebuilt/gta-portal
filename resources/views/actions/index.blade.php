@extends('layout')
@section('title','Action Plan')
@section('content')
@include('quick-actions.context')

<header class="page-heading"><div><p class="eyebrow">{{ $apprentice->first_name }} {{ $apprentice->last_name }}</p><h1>Action Plan</h1></div><div class="toolbar"><a class="button" href="{{ route('apprentices.show',$apprentice) }}">Apprentice profile</a>@if($manage)<a class="button primary" href="{{ route('actions.create', ['apprentice'=>$apprentice, 'review'=>$review?->id]) }}">Add SMART action</a>@endif</div></header>
@include('learning.errors')
@if($review)
    <p><a class="button" href="{{ route('reviews.edit', [$apprentice, $review]) }}#review-3">Return to review targets</a></p>
@endif
<nav class="toolbar" aria-label="Action status">
    @foreach(['To do', 'With tutor', 'Completed'] as $state)
        <a class="button" href="{{ route('actions.index', [$apprentice, 'state' => $state, 'review' => $review?->id, ...request()->only(['owner', 'due'])]) }}" @if(request('state') === $state) aria-current="page" @endif>{{ $state }} · {{ $counts[$state] ?? 0 }}</a>
    @endforeach
</nav>
<details class="panel" @if(request()->filled('owner') || request()->filled('state') || request()->filled('due')) open @endif>
    <summary>Filter actions</summary>
<form method="get" class="panel search">@if($review)<input type="hidden" name="review" value="{{ $review->id }}">@endif<div><label for="action-owner">Responsible person</label><select id="action-owner" name="owner"><option value="">All owners</option>@foreach(\App\Models\SmartAction::OWNERS as $owner)<option @selected(request('owner')===$owner)>{{ $owner }}</option>@endforeach</select></div><div><label for="action-state">Status</label><select id="action-state" name="state"><option value="">All statuses</option>@foreach(['To do','With tutor','Completed'] as $status)<option @selected(request('state')===$status)>{{ $status }}</option>@endforeach</select></div><div><label for="action-due">Due position</label><select id="action-due" name="due">@foreach([''=>'All dates','overdue'=>'Overdue','today'=>'Due today','checks'=>'Date checks'] as $key=>$label)<option value="{{ $key }}" @selected(request('due','')===$key)>{{ $label }}</option>@endforeach</select></div><button>Apply filters</button><a class="button" href="{{ route('actions.index', ['apprentice'=>$apprentice, 'review'=>$review?->id]) }}">Clear</a></form>
</details>
@if($open->isNotEmpty())
    <section aria-labelledby="open-targets">
        <h2 id="open-targets">Open actions</h2>
        @foreach($open as $a)@include('actions.row')@endforeach
    </section>
@endif
@if($completed->isNotEmpty())
    <details class="panel" data-reveal-on-link @if(request('state') === 'Completed' || $open->isEmpty()) open @endif>
        <summary>Completed actions · {{ $completed->count() }}</summary>
        @foreach($completed as $a)@include('actions.row')@endforeach
    </details>
@endif
@if($open->isEmpty() && $completed->isEmpty())
    <section class="panel"><h2>No matching actions</h2><p>Clear the filters to see other actions.</p></section>
@endif
@if($manage)<p><a href="{{ route('actions.proposals', $apprentice) }}">Review source action proposals</a></p>@endif
<details class="panel"><summary>How the Action Plan works</summary><p>To do includes returned actions. With tutor means the apprentice has submitted work for checking. Completed needs tutor confirmation or approval of the linked task.</p><p>Linked actions follow their source task’s status and deadline. Confidential support and Careers discussions belong in their restricted records.</p><p>Red means an open action is overdue. Blue means completed. Grey means no rating or insufficient dates. A missing date does not mean on track.</p></details>
@endsection

@extends('layout')
@section('title','Support and Retention Register')
@section('content')
<header class="page-heading">
    <h1>Support and Retention Register</h1>
    <div class="toolbar">
        <a href="{{ route('weekly-retention.index') }}">Weekly early warnings</a>
        <a href="{{ route('support.report') }}">Support and Retention report</a>
    </div>
</header>
@include('learning.errors')
@php($filters = request()->only(['search','by','marker']))
@if($meetingRows->isNotEmpty())<p><a class="button primary" href="{{route('support.worklist',['view'=>$view,...$filters,'meeting'=>$meetingRows->first()['person']->id])}}">Start meeting · {{$meetingRows->count()}} apprentices</a></p>@endif
<div class="toolbar register-views" aria-label="Register views">
    <a class="button" href="{{ route('support.worklist', ['view'=>'needs-review', ...$filters]) }}" @if($view === 'needs-review') aria-current="page" @endif>@if($view==='needs-review')<strong>Needs review · {{ $dueCount }}</strong>@else Needs review · {{ $dueCount }}@endif</a>
    <a class="button" href="{{ route('support.worklist', ['view'=>'all', ...$filters]) }}" @if($view === 'all') aria-current="page" @endif>@if($view==='all')<strong>All · {{ $allCount }}</strong>@else All · {{ $allCount }}@endif</a>
</div>
<form method="get" action="{{ route('support.worklist') }}" class="panel register-filter">
    <input type="hidden" name="view" value="{{ $view }}">
    <div class="search">
        <div><label for="support-search">Apprentice name or reference</label><input type="search" id="support-search" name="search" value="{{ $search }}" maxlength="100"></div>
        <button>Search</button>
        @if($search || request('by') || request('marker'))<a class="button" href="{{ route('support.worklist', ['view'=>$view]) }}">Clear</a>@endif
    </div>
    <details @if(request('by') || request('marker')) open @endif>
        <summary>More filters</summary>
        <div class="form-grid">
            <div><label for="review-by">Review by</label><input id="review-by" type="date" name="by" value="{{ request('by') }}"></div>
            <div><label for="register-marker">Marker</label><select id="register-marker" name="marker">
                <option value="">All markers</option>
                @foreach($codes as $code => $label)<option value="{{ $code }}" @selected(request('marker') === $code)>{{ $code }} · {{ $label }}</option>@endforeach
            </select></div>
        </div>
        <button>Apply filters</button>
    </details>
</form>
@if($employerMeeting)
    <details class="panel" id="employer-patterns"><summary>Employer attendance patterns</summary>@include('support.employer-meeting', ['meeting' => $employerMeeting])</details>
@endif
<section class="panel register-list" aria-label="Apprentices">
    @forelse($rows as $row)
        @php($person = $row['person'])
        @php($action = $row['next_action'])
        <article class="register-row">
            <div><h2>{{ $person->first_name }} {{ $person->last_name }}</h2><p class="muted">{{ $person->groupLabel() ?? 'Group not assigned' }}</p></div>
            <div>
                <div class="register-badges">
                    @foreach($row['codes'] as $code)<span class="register-marker" title="{{ $codes[$code] ?? $code }}" aria-label="{{ $code }} {{ $codes[$code] ?? '' }}">{{ $code }} · {{ $codes[$code] ?? 'Marker' }}</span>@endforeach
                </div>
                <span class="register-status {{ $row['overdue'] ? 'is-overdue' : ($row['needs_review'] ? 'is-attention' : 'is-positive') }}">{{ $row['overdue'] ? 'Review overdue' : ($row['needs_review'] ? 'Needs review' : 'No review due') }}</span>
            </div>
            <div class="register-action">
                @if($action)
                    <p><strong>{{ $action['title'] }}</strong></p>
                    <p class="muted">{{ $action['owner'] ?? 'Owner to assign' }} · <span @class(['register-overdue' => $action['due'] && $action['due'] < today()->toDateString()])>{{ $action['due'] ? ($action['due'] < today()->toDateString() ? 'Overdue ' : 'Due ').\Carbon\Carbon::parse($action['due'])->format('j M Y') : 'Due date to set' }}</span></p>
                @else
                    <p class="muted">No open action</p>
                @endif
            </div>
            <a class="button" href="{{ route('support.index', $person) }}">Open record<span class="sr-only"> for {{ $person->first_name }} {{ $person->last_name }} ({{ $person->reference }})</span></a>
        </article>
    @empty
        <p>{{ $view === 'needs-review' ? 'No apprentices need a review for these filters.' : 'No apprentices match these filters.' }}</p>
    @endforelse
    {{ $rows->links() }}
</section>
<details class="panel"><summary>Follow-ups and reminders</summary><ul>
    <li><a href="{{ route('support.worklist', ['state'=>'open']) }}">Concern and support plan follow-ups</a></li>
    <li><a href="{{ route('support-review-reminders.index') }}">My support review reminders</a></li>
</ul></details>
@endsection

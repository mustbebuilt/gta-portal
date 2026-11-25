@extends('layout')
@section('title', 'Work to mark')
@section('content')
<header class="page-heading"><h1>Work to mark</h1></header>
@if($canMarkTasks)
    @include('marking.switch')
@endif
<section class="panel">
    <h2>Maths and English evidence</h2>
    <p>{{ $topics->total() }} {{ \Illuminate\Support\Str::plural('submission', $topics->total()) }} awaiting review</p>
    @if($nextTopic)
        <a class="button primary" href="{{ \App\Support\SubjectMarkingQueue::url($nextTopic, $filters) }}">Start evidence review</a>
    @endif
    <details @if($filters['subject'] !== 'all' || $filters['search'] !== '') open @endif>
        <summary>Filter submissions</summary>
        <form method="get" action="{{ route('marking.index') }}" class="search">
            <input type="hidden" name="kind" value="skills">
            @if(count($subjects) > 1)
                <label>Subject <select name="subject">
                    <option value="all">All permitted subjects</option>
                    @foreach($subjects as $subject)
                        <option value="{{ $subject }}" @selected($filters['subject'] === $subject)>{{ ucfirst($subject) }}</option>
                    @endforeach
                </select></label>
            @endif
            <label>Apprentice name or reference <input name="search" maxlength="100" value="{{ $filters['search'] }}"></label>
            <button>Apply filters</button>
            <a href="{{ \App\Support\SubjectMarkingQueue::indexUrl() }}">Clear filters</a>
        </form>
    </details>
    <div class="work-list">
        @forelse($topics as $topic)
            <article class="work-row">
                <div><h3>{{ $topic->topic }}</h3><p>{{ $topic->apprentice->first_name }} {{ $topic->apprentice->last_name }} · {{ $topic->apprentice->reference }} · {{ ucfirst($topic->subject) }} {{ $topic->level }}</p></div>
                <a class="button" href="{{ \App\Support\SubjectMarkingQueue::url($topic->id, $filters) }}">Review evidence<span class="sr-only"> for {{ $topic->topic }}</span></a>
            </article>
        @empty
            <p>No evidence awaiting review in this view.</p>
        @endforelse
    </div>
    {{ $topics->links() }}
</section>
<p><a href="{{ route('em.caseload') }}">Maths and English caseload</a></p>
@endsection

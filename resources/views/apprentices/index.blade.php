@extends('layout')
@section('title', 'Apprentices')
@section('content')
<header class="page-heading">
    <h1>Apprentices</h1>
    <div class="toolbar">
        @if(\App\Support\LearningAccess::manager(auth()->user()))
            <a class="button" href="{{ route('apprentices.archive') }}">Apprentice archive</a>
        @endif
        @can('create', App\Models\Apprentice::class)
            <form method="post" action="{{ route('setup68.create') }}">@csrf<button class="primary">Add apprentice</button></form>
        @endcan
    </div>
</header>
@include('learning.errors')
<section class="panel">
    <form method="get" action="{{ route('apprentices.index') }}">
        <div class="form-grid">
            <div>
                <label for="search">Name or reference</label>
                <input id="search" name="search" value="{{ $search }}" maxlength="100">
            </div>
            @foreach(['group' => 'Tutor group', 'programme' => 'Programme', 'tutor' => 'Main tutor'] as $key => $label)
                @if(isset($options[$key]) && ($options[$key]->isNotEmpty() || isset($filters[$key])))
                    <div>
                        <label for="filter-{{ $key }}">{{ $label }}</label>
                        <select id="filter-{{ $key }}" name="{{ $key }}">
                            <option value="">Any {{ strtolower($label) }}</option>
                            @if(isset($filters[$key]) && !$options[$key]->has((int) $filters[$key]))
                                <option value="{{ $filters[$key] }}" selected>Selection unavailable</option>
                            @endif
                            @foreach($options[$key] as $id => $name)
                                <option value="{{ $id }}" @selected((int) ($filters[$key] ?? 0) === $id)>{{ $name }}</option>
                            @endforeach
                        </select>
                    </div>
                @endif
            @endforeach
        </div>
        <div class="form-actions">
            <button class="primary">Find apprentices</button>
            @if($filters)<a class="button" href="{{ route('apprentices.index') }}">Clear filters</a>@endif
        </div>
    </form>
    <p>{{ $records->total() }} {{ \Illuminate\Support\Str::plural('apprentice', $records->total()) }}</p>
    <div class="work-list">
        @php($origin = $returnFilters ? ['list' => $returnFilters] : [])
        @forelse($records as $record)
            <article class="work-row">
                <div>
                    <h2>{{ $record->first_name }} {{ $record->last_name }}</h2>
                    <p>{{ $record->reference }} · {{ $record->programmeLabel() ?? 'Programme not recorded' }}</p>
                    <p class="muted">{{ $record->groupLabel() ?? 'Group not assigned' }} · {{ $record->tutor?->name ?? 'Tutor not assigned' }}</p>
                </div>
                <div class="toolbar">
                    <a class="button primary" href="{{ route('apprentices.show', ['apprentice' => $record] + $origin) }}">Open record<span class="sr-only"> for {{ $record->reference }}</span></a>
                    <a class="button" href="{{ route('progress66.index', ['apprentice' => $record->id] + $origin) }}">View progress<span class="sr-only"> for {{ $record->reference }}</span></a>
                </div>
            </article>
        @empty
            <p>No matching apprentice records.</p>
        @endforelse
    </div>
    @if($records->hasPages())
        <nav class="pagination" aria-label="Apprentice record pages">
            @if($records->previousPageUrl())<a class="button" href="{{ $records->previousPageUrl() }}">Previous page</a>@endif
            <span>Page {{ $records->currentPage() }} of {{ $records->lastPage() }}</span>
            @if($records->nextPageUrl())<a class="button" href="{{ $records->nextPageUrl() }}">Next page</a>@endif
        </nav>
    @endif
</section>
@endsection

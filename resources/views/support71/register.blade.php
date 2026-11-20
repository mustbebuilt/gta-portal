@extends('layout')
@section('title', 'Support and Retention Register')
@section('content')
<header class="page-heading">
    <div>
        <p class="eyebrow">Support and Retention</p>
        <h1>Support and Retention Register</h1>
        <p class="muted">One register for open markers, scheduled reviews and shared actions. Safeguarding details stay outside this list.</p>
    </div>
    <div class="toolbar">
        <a class="button {{ $view === 'needs_review' ? 'primary' : '' }}" href="{{ route('support-register71.index', ['view' => 'needs_review']) }}">Needs review ({{ $summary['distinct_needs_review'] }})</a>
        <a class="button {{ $view === 'all' ? 'primary' : '' }}" href="{{ route('support-register71.index', ['view' => 'all']) }}">All open markers ({{ $summary['distinct_apprentices'] }})</a>
        <a class="button" href="{{ route('support-register71.reports') }}">Reports</a>
        <a class="button" href="{{ route('support.worklist') }}">Legacy worklist</a>
    </div>
</header>
@include('learning.errors')

<section class="panel">
    <form class="search" method="get">
        <input type="hidden" name="view" value="{{ $view }}">
        <div><label for="sr-search">Apprentice name or reference</label><input id="sr-search" name="search" value="{{ request('search') }}" maxlength="100"></div>
        <div><label for="sr-code">Marker code (IM1–IM40)</label><select id="sr-code" name="code"><option value="">All markers</option>@foreach($codes as $c => $label)<option value="{{ $c }}" @selected(request('code') === $c)>{{ $c }} — {{ $label }}</option>@endforeach</select></div>
        <button class="primary">Filter</button>
        @if(request('search') || request('code'))<a class="button" href="{{ route('support-register71.index', ['view' => $view]) }}">Clear</a>@endif
    </form>
</section>

@if($canManage)
<details class="panel"><summary>Add apprentice marker to register (IM1–IM40)</summary>
    <form method="post" action="{{ route('support-register71.add-marker') }}">
        @csrf
        <div class="form-grid">
            <div><label for="add-apprentice">Existing apprentice *</label><select id="add-apprentice" name="apprentice_id" required><option value="">Choose apprentice</option>@foreach($allApprentices as $a)<option value="{{ $a->id }}">{{ $a->first_name }} {{ $a->last_name }} ({{ $a->reference }})</option>@endforeach</select></div>
            <div><label for="add-code">Initial concern or support need (IM1–IM40) *</label><select id="add-code" name="code" required><option value="">Choose approved marker</option>@foreach($codes as $c => $label)<option value="{{ $c }}">{{ $c }} — {{ $label }}</option>@endforeach</select></div>
            <div><label for="add-interval">Review frequency *</label><select id="add-interval" name="interval_key" required>@foreach($intervals as $k => $label)<option value="{{ $k }}" @selected($k === 'monthly')>{{ $label }}</option>@endforeach</select></div>
            <div><label for="add-owner">Responsible owner *</label><select id="add-owner" name="owner_id" required>@foreach($staffOwners as $staff)<option value="{{ $staff->id }}" @selected($staff->id === auth()->id())>{{ $staff->name }}</option>@endforeach</select></div>
            <div class="full"><label for="add-note">Context note (required for IM40 Other, max 250 chars for IM40)</label><textarea id="add-note" name="note" rows="2" maxlength="2000"></textarea></div>
        </div>
        <p class="muted">Creates the selected concern/marker and schedule only. Does not create a support plan, funding claim or OTJ hours.</p>
        <button class="primary">Add to register</button>
    </form>
</details>
@endif

@forelse($rows as $row)
@php($a = $row['apprentice'])
<section class="panel">
    <div class="work-row">
        <div>
            <h2>{{ $a->first_name }} {{ $a->last_name }} <small>({{ $a->reference }})</small></h2>
            <p class="muted">{{ $a->employerLabel() ?? 'Employer not recorded' }} · {{ $a->groupLabel() ?? 'Group not assigned' }} · Active support plans: {{ $row['active_plan_count'] }} · Open actions: {{ $row['open_action_count'] }}</p>
        </div>
        <div class="toolbar">
            <a class="button" href="{{ route('apprentices.show', $a) }}">Open profile</a>
            @if($row['can_private'])
                <a class="button" href="{{ route('support.index', $a) }}">Private support record</a>
                <a class="button" href="{{ route('routine-support.show', $a) }}">Routine support</a>
            @endif
        </div>
    </div>

    <div class="table-scroll">
        <table>
            <thead><tr><th>Marker</th><th>Status</th><th>Frequency</th><th>Last formal review</th><th>Next due</th>@if($row['can_private'])<th>Review / update</th>@endif</tr></thead>
            <tbody>
            @foreach($row['markers'] as $m)
                <tr>
                    <td><strong>{{ $m['code'] ?? 'Uncoded' }}</strong> — {{ $m['label'] }}</td>
                    <td><span class="status">{{ $m['status_label'] }}</span></td>
                    <td>{{ $intervals[$m['interval_key']] ?? 'Needs checking' }}</td>
                    <td>{{ $m['last_formal_review_date'] ?? 'Not yet established' }}</td>
                    <td>{{ $m['next_due_date'] ?? 'Not set' }}</td>
                    @if($row['can_private'])
                    <td>
                        @if($m['schedule'])
                        <details><summary>Quick review</summary>
                            <form method="post" action="{{ route('support-register71.review', $m['schedule']) }}">
                                @csrf
                                <input type="hidden" name="version" value="{{ $m['schedule']->version }}">
                                <label>Action<select name="kind"><option value="formal_review">Record formal review (Register)</option><option value="discussion_only">Log discussion only (keeps due date)</option><option value="change_frequency">Change frequency (needs reason)</option><option value="close_marker">Close marker episode</option></select></label>
                                <label>Review date<input type="date" name="review_date" value="{{ $today }}"></label>
                                <label>Frequency<select name="interval_key">@foreach($intervals as $k => $lbl)<option value="{{ $k }}" @selected($k === $m['interval_key'])>{{ $lbl }}</option>@endforeach</select></label>
                                <label>Note / reason<textarea name="note" rows="2" maxlength="2000"></textarea></label>
                                <button class="primary">Save</button>
                            </form>
                        </details>
                        @else
                        <span class="muted">Legacy concern — manage in support record</span>
                        @endif
                    </td>
                    @endif
                </tr>
            @endforeach
            </tbody>
        </table>
    </div>

    @if($row['can_private'])
    <details><summary>Shared actions ({{ $row['actions']->count() }})</summary>
        @foreach($row['actions'] as $act)
            <div class="work-row">
                <div><strong>{{ $act->details['summary'] ?? '' }}</strong> · Owner: {{ $act->owner?->name }} · Due {{ $act->due_date->toDateString() }} · <span class="status">{{ $act->state }}</span></div>
                @if($act->state === 'Open')
                <form method="post" action="{{ route('support-register71.complete-action', $act) }}">@csrf<button>Mark complete</button></form>
                @endif
            </div>
        @endforeach
        <form method="post" action="{{ route('support-register71.add-action') }}">
            @csrf
            <input type="hidden" name="apprentice_id" value="{{ $a->id }}">
            <input type="hidden" name="request_uuid" value="{{ $actionUuid }}">
            <div class="form-grid">
                <div class="full"><label>New follow-up action<input name="summary" required maxlength="2000"></label></div>
                <div><label>Owner<select name="owner_id" required>@foreach($staffOwners as $staff)<option value="{{ $staff->id }}" @selected($staff->id === auth()->id())>{{ $staff->name }}</option>@endforeach</select></label></div>
                <div><label>Due date<input type="date" name="due_date" required value="{{ $today }}"></label></div>
            </div>
            <button>Add action</button>
        </form>
    </details>
    @endif
</section>
@empty
<section class="panel"><p>No apprentices match this register filter.</p></section>
@endforelse
@endsection

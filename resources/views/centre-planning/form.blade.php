@extends('layout')
@section('title', 'Plan an activity')
@section('content')
<header class="page-heading"><h1>{{ $p ? 'Edit draft' : 'Plan an activity' }}</h1></header>
@include('centre-planning.links')
@include('learning.errors')

<form class="panel" method="post" data-timetable-activity data-flexible-kind="{{ \App\Support\CentrePlanning::FLEXIBLE_DAY }}" action="{{ $p ? route('centre-planning.update', $p) : route('centre-planning.save') }}">
    @csrf
    <input type="hidden" name="request_token" value="{{ $token }}">
    <input type="hidden" name="version" value="{{ $p?->version ?? 0 }}">
    <div class="form-grid">
        <label>Activity
            <select name="kind">
                @foreach(($p ? \App\Support\CentrePlanning::TYPES : \App\Support\CentrePlanning::NEW_ACTIVITIES) as $kind)
                    <option @selected(old('kind', $d['kind'] ?? '') === $kind)>{{ $kind }}</option>
                @endforeach
            </select>
        </label>
        <label>Staff
            <select name="staff_id" required>
                @foreach($staff as $u)
                    <option value="{{ $u->id }}" @selected(old('staff_id', $d['staff_id'] ?? null) == $u->id)>{{ $u->name }}</option>
                @endforeach
            </select>
        </label>
        <label>Start date <input type="date" name="date" value="{{ old('date', $d['date'] ?? '') }}" required></label>
    </div>
    <p data-flexible-help hidden>Continue to Flexible Duties Days to check working time and choose how often to repeat the day.</p>
    <fieldset data-booked-activity>
        <legend>Activity details</legend>
        <div class="form-grid">
            <div class="full">
                <label>Timetable title <input name="title" maxlength="160" value="{{ old('title', $d['title'] ?? '') }}" aria-describedby="timetable-title-hint"></label>
                <p id="timetable-title-hint" class="muted">This appears on the timetable. Use a short title such as Brake systems workshop. Keep learner names and private details out of the title.</p>
            </div>
            @foreach(['start' => '09:00', 'end' => '16:00'] as $field => $default)
                <label>{{ ucfirst($field) }} time <input type="time" name="{{ $field }}" value="{{ old($field, $d[$field] ?? $default) }}" required></label>
            @endforeach
            <label>Site
                <select name="site">
                    @foreach(\App\Support\CentrePlanning::SITES as $site)
                        <option @selected(old('site', $d['site'] ?? '') === $site)>{{ $site }}</option>
                    @endforeach
                </select>
            </label>
            <label>Room
                <select name="room_id">
                    <option value="">No room</option>
                    @foreach($rooms as $room)
                        <option value="{{ $room->id }}" @selected(old('room_id', $d['room_id'] ?? null) == $room->id)>{{ $room->site }} · {{ $room->name }}</option>
                    @endforeach
                </select>
            </label>
            <fieldset data-teaching-activity class="full">
                <legend>Teaching group</legend>
                <div class="form-grid">
                    <label>Group
                        <select name="group_id">
                            <option value="">Choose a group</option>
                            @foreach($groups as $group)
                                <option value="{{ $group->id }}" @selected(old('group_id', $d['group_id'] ?? null) == $group->id)>{{ $group->name }}</option>
                            @endforeach
                        </select>
                    </label>
                    <label>Expected headcount <input type="number" min="1" max="200" name="headcount" value="{{ old('headcount', $d['headcount'] ?? '') }}"></label>
                </div>
                <p class="muted">Choose the group’s primary tutor and a suitable room.</p>
            </fieldset>
        </div>
        <details @if((int) old('weeks', $d['weeks'] ?? 1) !== 1 || old('excluded_dates_text', $d['excluded_dates'] ?? []) || $errors->hasAny(['weeks', 'excluded_dates_text'])) open @endif>
            <summary>Repeat weekly or exclude dates</summary>
            <label>Weekly occurrences <input type="number" min="1" max="53" name="weeks" value="{{ old('weeks', $d['weeks'] ?? 1) }}" required></label>
            <label>Dates to leave out (YYYY-MM-DD, one per line)<textarea name="excluded_dates_text">{{ old('excluded_dates_text', implode("\n", $d['excluded_dates'] ?? [])) }}</textarea></label>
        </details>
        <label><input type="checkbox" name="confirmed" value="1" @checked(old('confirmed'))> Confirm these planning details for the draft.</label>
        <p class="muted">Check and publish the draft to put it on the timetable.</p>
    </fieldset>
    <button class="primary" data-timetable-submit>{{ $p ? 'Save draft for checking' : 'Continue' }}</button>
</form>
<script src="{{ asset('assets/timetable-activity.js') }}" defer></script>
@endsection

@php
    $selectedGroup = (int) old('tutor_group_id', request('group'));
    $selectedSubjects = array_values(array_filter((array) old('subjects', []), 'is_string'));
    $useWeekly = $plannerOptions['timing'] && old('timing') !== 'custom';
    $customBreaks = collect(old('breaks', $plannerOptions['timing']['breaks'] ?? []))
        ->filter(fn ($break) => is_array($break))->take(10)->values()->all() ?: [[]];
@endphp
<details class="panel" id="session-planner" data-reveal-on-link @if($errors->any() || request()->filled('plan_date')) open @endif>
    <summary>Plan a new session · exceptions</summary>
    <noscript>
        <form method="get" action="{{ route('attendance.index') }}#session-planner">
            <label>Group <select name="group" required>
                <option value="">Choose group</option>
                @foreach($groups->where('active', true) as $group)
                    <option value="{{ $group->id }}" @selected($selectedGroup === $group->id)>{{ $group->name }}</option>
                @endforeach
            </select></label>
            <label>Date <input type="date" name="plan_date" value="{{ $plannerDate }}" required></label>
            <button>Load subjects and schedule</button>
        </form>
    </noscript>
    <form method="post" action="{{ route('attendance.create') }}" data-session-planner>
        @csrf
        <input type="hidden" name="planner" value="subjects">
        <input type="hidden" name="timing" value="{{ $useWeekly ? 'weekly' : 'custom' }}" data-plan-timing>
        <div class="form-grid">
            <label>Group <select name="tutor_group_id" required>
                <option value="">Choose group</option>
                @foreach($groups->where('active', true) as $group)
                    <option value="{{ $group->id }}" data-options="{{ route('attendance.plan-options', $group) }}" @selected($selectedGroup === $group->id)>{{ $group->name }}</option>
                @endforeach
            </select></label>
            <label>Date <input type="date" name="session_date" value="{{ $plannerDate }}" required></label>
        </div>
        <div data-plan-picker hidden>
            <label>Subject from the scheme of work
                <select data-plan-subject><option value="">Choose a subject</option></select>
            </label>
            <ul data-plan-selected aria-label="Selected subjects"></ul>
        </div>
        <noscript>
            <label>Subjects <select name="subjects[]" multiple>
                @foreach($plannerOptions['subjects'] as $subject)
                    <option value="{{ $subject['subjectId'] }}" @selected(in_array($subject['subjectId'], $selectedSubjects, true))>{{ $subject['label'] }}</option>
                @endforeach
            </select></label>
            <p>Use Load subjects and schedule after changing group or date.</p>
        </noscript>
        <details @if(old('other_subject')) open @endif>
            <summary>Other subject</summary>
            <label>Subject outside the scheme of work <input name="other_subject" maxlength="250" value="{{ old('other_subject') }}"></label>
        </details>
        <p data-plan-message role="status"></p>
        <p data-plan-schedule>@if($useWeekly)Using the weekly schedule, {{ $plannerOptions['timing']['start'] }}–{{ $plannerOptions['timing']['end'] }}.@endif</p>
        <button type="button" data-plan-change hidden>Use different times</button>
        <fieldset data-plan-custom @disabled($useWeekly) @if($useWeekly || !$selectedGroup) hidden @endif>
            <legend>Session times</legend>
            <div class="form-grid">
                <label>Start <input type="time" name="start_time" value="{{ old('start_time') }}" required></label>
                <label>End <input type="time" name="end_time" value="{{ old('end_time') }}" required></label>
            </div>
            <details @if($errors->has('breaks') || $errors->has('breaks_checked')) open @endif>
                <summary>Excluded breaks</summary>
                @foreach($customBreaks as $index => $break)
                    <div class="form-grid">
                        <label>Break start <input type="time" name="breaks[{{ $index }}][start]" value="{{ $break['start'] ?? '' }}"></label>
                        <label>Break end <input type="time" name="breaks[{{ $index }}][end]" value="{{ $break['end'] ?? '' }}"></label>
                    </div>
                @endforeach
                <button type="button" data-plan-add-break hidden>Add another break</button>
            </details>
            <label class="check"><input type="checkbox" name="breaks_checked" value="1" @checked(old('breaks_checked')) required> I checked which breaks to exclude.</label>
        </fieldset>
        <button class="primary" data-plan-save>Save planned session</button>
        <script type="application/json" data-plan-options>@json($plannerOptions, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT)</script>
        <script type="application/json" data-plan-initial>@json($selectedSubjects, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT)</script>
    </form>
</details>
<script src="{{ asset('assets/session-planner.js') }}?v=87" defer></script>

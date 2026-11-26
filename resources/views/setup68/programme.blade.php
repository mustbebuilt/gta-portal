@php
    $saved = $p['programme'] ?? [];
    $posted = old('data', []);
    $selectedValue = fn ($field) => is_scalar($posted[$field] ?? $saved[$field] ?? null) ? (string) ($posted[$field] ?? $saved[$field]) : '';
    $selected = $selectedValue('template_id');
    $selectedTemplate = $templates->first(fn ($template) => (string) $template->id === (string) $selected);
    $preview = $templates->keyBy('id')->map(fn ($template) => [
        'hours' => number_format($template->template['full_hours'], 2),
        'ksbs' => count($template->template['ksbs']),
        'blocks' => collect($template->template['blocks'])->sortBy('number')->map(fn ($block) => [
            'number' => $block['number'], 'name' => $block['title'], 'hours' => number_format($block['hours'], 2),
        ])->values(),
    ]);
    $initialPreview = $selectedTemplate ? $preview[$selectedTemplate->id] : null;
@endphp
<label for="template">Apprenticeship standard and version</label>
<select id="template" name="data[template_id]">
    <option value="">Choose a programme</option>
    @foreach($templates as $template)
        <option value="{{ $template->id }}" data-programme="{{ $template->programme_id }}" @selected($selectedTemplate?->id === $template->id)>
            {{ $template->template['name'] ?? $template->template['standard_code'] }} · {{ $template->template['standard_code'] }} v{{ ltrim($template->template['standard_version'], 'vV') }}
        </option>
    @endforeach
</select>
@if($selected && !$selectedTemplate)
    <p class="warn">The programme saved in this draft needs checking. Your saved details stay in the draft until you save a programme choice.</p>
@endif
<div data-programme-preview @unless($initialPreview) hidden @endunless>
    <p data-programme-totals role="status">
        @if($initialPreview)
            {{ $initialPreview['hours'] }} starting OTJ hours · {{ $initialPreview['ksbs'] }} KSBs · {{ count($initialPreview['blocks']) }} blocks
        @endif
    </p>
    <details id="programme-blocks">
        <summary>See the training blocks</summary>
        <ul data-programme-blocks>
            @foreach($initialPreview['blocks'] ?? [] as $block)
                <li>{{ $block['number'] }}. {{ $block['name'] }} · {{ $block['hours'] }} hours</li>
            @endforeach
        </ul>
    </details>
</div>
<script type="application/json" id="setup-programme-data">@json($preview, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT)</script>
@if($unavailableProgrammes)
    <details id="programme-availability" @if($templates->isEmpty() || ($selected && !$selectedTemplate)) open @endif>
        <summary>Programme missing from the list?</summary>
        <ul>
            @foreach($unavailableProgrammes as $missing)
                <li>{{ $missing['name'] }} · {{ $missing['code'] }} v{{ ltrim($missing['version'], 'vV') }} · {{ $missing['reason'] }}</li>
            @endforeach
        </ul>
        <p>Give the curriculum administrator the standard, version and reason shown here. You can save this draft while the source is checked.</p>
    </details>
@endif
@if(!empty($p['rpl']['adjustments']))
    <p>Choosing a different programme replaces the saved prior learning reductions for this draft.</p>
@endif
<div class="setup-grid">
    <div><label for="start">First day in learning</label><input type="date" id="start" name="data[start_date]" value="{{ $selectedValue('start_date') }}"></div>
    <div>
        <label for="group">Tutor group</label>
        <select id="group" name="data[tutor_group_id]">
            <option value="">Not assigned</option>
            @foreach($groups as $group)
                <option value="{{ $group->id }}" data-programme="{{ $group->programme_id }}" @selected($selectedValue('tutor_group_id') === (string) $group->id)>{{ $group->name }}</option>
            @endforeach
        </select>
    </div>
    <div>
        <label for="tutor">Main tutor</label>
        <select id="tutor" name="data[tutor_id]">
            <option value="">Not assigned</option>
            @foreach($tutors as $staff)
                <option value="{{ $staff->id }}" @selected($selectedValue('tutor_id') === (string) $staff->id)>{{ $staff->name }}</option>
            @endforeach
        </select>
    </div>
</div>
<details id="other-setup-staff" @if(collect($review['issues'])->contains(fn ($issue) => in_array($issue['field'], ['mentor_id','careers_adviser_id','maths_tutor_id','english_tutor_id'], true))) open @endif>
    <summary>Other staff assignments</summary>
    <div class="setup-grid">
        @foreach(['mentor_id'=>'Learning & Progress Mentor (GTA role)', 'careers_adviser_id'=>'Careers adviser', 'maths_tutor_id'=>'Maths tutor', 'english_tutor_id'=>'English tutor'] as $field => $label)
            <div>
                <label for="{{ $field }}">{{ $label }}</label>
                <select id="{{ $field }}" name="data[{{ $field }}]">
                    <option value="">Not assigned</option>
                    @foreach($staffOptions[$field] as $id => $name)
                        <option value="{{ $id }}" @selected($selectedValue($field) === (string) $id)>{{ $name }}</option>
                    @endforeach
                </select>
            </div>
        @endforeach
    </div>
</details>

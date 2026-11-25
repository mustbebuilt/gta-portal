<div data-staff-template @isset($focusToken) data-staff-template-token="{{ $focusToken }}" @endisset hidden>
    <label>{{ $pickerLabel }}
        <select data-staff-template-choice>
            <option value="">Choose a template</option>
            <option value="custom" data-fill="{}">Custom</option>
            @foreach(config('staff_templates.'.$family, []) as $group => $templates)
                <optgroup label="{{ $group }}">
                    @foreach($templates as $label => $values)
                        @php($fields = array_combine($fieldNames, $values) + ($defaults ?? []))
                        <option value="{{ $label }}" data-fill="{{ json_encode($fields, JSON_THROW_ON_ERROR) }}">{{ $label }}</option>
                    @endforeach
                </optgroup>
            @endforeach
        </select>
    </label>
    @isset($focusToken)
        <p class="muted">Replace {{ $focusToken }} with the specific point in this apprentice’s work.</p>
    @endisset
    <p class="muted" data-staff-template-status role="status"></p>
</div>
@once
    <script src="{{ asset('assets/staff-templates.js') }}" defer></script>
@endonce

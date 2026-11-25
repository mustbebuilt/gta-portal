<div data-staff-template hidden>
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
    <p class="muted" data-staff-template-status role="status"></p>
</div>
@once
    <script src="{{ asset('assets/staff-templates.js') }}" defer></script>
@endonce

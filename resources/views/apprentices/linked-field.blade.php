<div><label for="{{ $field }}">{{ $label }}</label><select id="{{ $field }}" name="{{ $field }}"><option value="">Not assigned</option>@foreach($choices as $value=>$text)<option value="{{ $value }}" @selected((string)old($field,$apprentice->$field)===(string)$value)>{{ $text }}</option>@endforeach</select>
@if(!empty($previous) && !$apprentice->$field)<small class="muted">Previous unlinked entry: {{ $previous }}. Select a confirmed match when available.</small>@endif
@error($field)<small class="field-error">{{ $message }}</small>@enderror</div>

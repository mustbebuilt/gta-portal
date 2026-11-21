@if($session->ended())
@php
    $prefix = 'development.'.$a->id;
    $field = 'development['.$a->id.']';
    $saved = $record ? \App\Support\MonthlyDevelopment::read($record) : null;
@endphp
<details id="monthly-development-{{ $a->id }}" @if(old($prefix.'.enabled') || request()->integer('snapshot_for') === $a->id) open @endif>
    <summary>Monthly personal development · {{ $session->session_date->format('M Y') }} · {{ $saved ? 'Recorded' : 'Due' }}</summary>
    <p class="muted">Record one snapshot per calendar month. Use observations from attended training.</p>
    @if(request()->integer('snapshot_for') === $a->id)
        <p><a href="{{ route('attendance.index', ['group' => $session->tutor_group_id]).'#monthly-outstanding' }}">Back to the monthly checklist</a></p>
    @endif
    <label class="check"><input type="checkbox" name="{{ $field }}[enabled]" value="1" @checked(old($prefix.'.enabled'))> {{ $saved ? 'Update' : 'Save' }} this month’s snapshot with the register</label>
    <input type="hidden" name="{{ $field }}[version]" value="{{ old($prefix.'.version', $record?->version ?? 0) }}">
    <div class="form-grid">
        @foreach(\App\Support\ApprenticeReviewData::DEVELOPMENT_AREAS as $key => $label)
            <div>
                <label for="development-{{ $a->id }}-{{ $key }}">{{ $label }}</label>
                <select id="development-{{ $a->id }}-{{ $key }}" name="{{ $field }}[ratings][{{ $key }}]">
                    <option value="">Choose a rating</option>
                    @foreach(\App\Support\ApprenticeReviewData::RATINGS as $rating)
                        <option value="{{ $rating }}" @selected(old($prefix.'.ratings.'.$key, $saved['ratings'][$key] ?? null) === $rating)>{{ $rating }}</option>
                    @endforeach
                </select>
            </div>
        @endforeach
        <div class="full">
            <label for="development-{{ $a->id }}-comment">Tutor comment (optional)</label>
            <textarea id="development-{{ $a->id }}-comment" name="{{ $field }}[comment]" rows="2" maxlength="1000">{{ old($prefix.'.comment', $saved['comment'] ?? '') }}</textarea>
        </div>
    </div>
</details>
@endif

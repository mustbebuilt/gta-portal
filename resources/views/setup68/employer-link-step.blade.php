@php
    $saved = $p['employer'] ?? [];
    $oldSelection = old('data.id', $saved['id'] ?? '');
    $selected = is_scalar($oldSelection) ? (string) $oldSelection : '';
    $stepIssues = collect($review['issues'])->where('step', 5);
    $agreementIssue = $stepIssues->contains(fn ($issue) => ($issue['field'] ?? null) === 'agreement');
@endphp

@if($review['employer'])
    <p><strong>Saved employer:</strong> {{ $review['employer']->name }} · {{ $review['employer']->reference }}</p>
@elseif(filled($saved['name'] ?? null))
    <p><strong>New employer in this draft:</strong> {{ $saved['name'] }}</p>
@endif
@if($stepIssues->isNotEmpty())
    <div class="warn">
        <h2>Employer details to check</h2>
        <ul>@foreach($stepIssues as $issue)<li>{{ $issue['message'] }}</li>@endforeach</ul>
    </div>
@endif
@if($employers->isNotEmpty() || $selected)
    <label for="employer">Employer to link</label>
    <select id="employer" name="data[id]">
        <option value="">Choose an employer or add a new one</option>
        @foreach($employers as $employer)
            <option value="{{ $employer->id }}" @selected($selected === (string) $employer->id)>
                {{ $employer->name }} · {{ $employer->reference }}@unless($employer->active) · Inactive @endunless
            </option>
        @endforeach
    </select>
@endif
<details id="add-employer" @if(!$selected && (filled($saved['name'] ?? null) || old('data.name') || ($employerSearch !== '' && !$employerMatchCount))) open @endif>
    <summary>Add a new employer</summary>
    @include('setup68.employer-fields', ['employerFields' => old('data', $saved)])
</details>
<label><input type="checkbox" name="data[use_contact]" value="1" @checked(old('data.use_contact', $saved['use_contact'] ?? false))> Use the employer’s workplace contact or mentor</label>
@if(($p['rpl']['mode'] ?? null) === 'applies')
    <h2>Employer agreement</h2>
    @if(!$agreementIssue)
        <p>Verified for the saved employer and prior learning record.</p>
    @elseif(!$review['employer'] && !filled($saved['name'] ?? null))
        <p>Save the employer before verifying the agreement.</p>
    @endif
    <label for="agreement">Employer agreement reference</label>
    <input id="agreement" name="data[agreement_ref]" value="{{ old('data.agreement_ref', $saved['agreement_ref'] ?? '') }}" maxlength="1000">
    <label><input type="checkbox" name="data[agreement_verified]" value="1" @checked(!$agreementIssue && old('data.agreement_verified', $saved['agreement_verified'] ?? false))> I checked this agreement against the employer and the current prior learning record.</label>
@endif

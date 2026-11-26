<label @if($choiceWhenReferred ?? false) data-referral-choice @endif>{{ $choiceLabel }}
    <select name="{{ $choiceName }}" @if($choiceRequired ?? false) required @endif>
        <option value="">Choose</option>
        @foreach($choices as $code => $label)
            <option value="{{ $code }}" @selected(($choiceValue ?? '') === $code)>{{ $label }}</option>
        @endforeach
    </select>
</label>

@if($choiceWhenReferred ?? false)
    @once
        <script src="{{ asset('assets/referral-choice.js') }}" defer></script>
    @endonce
@endif

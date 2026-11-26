<section class="panel">
    <h2>What did the group learn?</h2>
    @if($deliveryDraft)
        <p>The planned subjects are already selected. Keep the topics taught in full and add a custom note for anything else.</p>
    @endif
    @php
        $selected = old('training') !== null ? (array) old('training.subjects', []) : array_column($structuredTraining['items'] ?? $deliveryDraft, 'subjectId');
        $byArea = collect($trainingOptions)->groupBy('area');
    @endphp
    <div class="form-grid">
        @foreach(\App\Support\RegisterTraining::AREAS as $area => $label)
            @php($custom = old("training.{$area}", collect($structuredTraining['items'] ?? [])->where('area', $area)->filter(fn ($item) => str_starts_with($item['subjectId'], 'custom:'))->pluck('label')->implode("\n")))
            <fieldset data-training-area>
                <legend>{{ $label }}</legend>
                <label data-topic-picker hidden>
                    {{ $label }} subject
                    <select aria-label="{{ $label }} subject"><option value="">Choose a subject</option></select>
                </label>
                <ul data-topic-list aria-label="{{ $label }} selected subjects" hidden></ul>
                @if($byArea->get($area, collect())->isNotEmpty())
                    <details data-topic-fallback>
                        <summary>{{ $area === 'vocational' ? 'Topics from approved plans and schemes of work' : 'Choose subjects' }}</summary>
                        @foreach($byArea[$area] as $subject)
                            <label class="check"><input type="checkbox" name="training[subjects][]" value="{{ $subject['subjectId'] }}"
                                data-register-topic data-topic-area="{{ $area }}" data-topic-label="{{ $subject['label'] }}"
                                @checked(in_array($subject['subjectId'], $selected, true))> {{ $subject['label'] }}</label>
                        @endforeach
                    </details>
                @endif
                <details data-training-custom @if(filled($custom)) open @endif>
                    <summary>Custom</summary>
                    @if($area === 'vocational' && !$session->planned_subjects)
                        <button type="button" data-use-planned-training hidden>Use planned vocational training</button>
                        <template id="planned-training-copy">{{ $session->planned_learning }}</template>
                    @endif
                    <label>{{ $label }} custom note
                        <textarea name="training[{{ $area }}]" rows="2">{{ $custom }}</textarea>
                    </label>
                </details>
            </fieldset>
        @endforeach
    </div>
    <details @if($errors->has('delivered_learning') || ($session->delivered_learning && !$deliveryDraft)) open @endif>
        <summary>OTJ delivery note</summary>
        <label for="delivered-learning">Eligible occupational learning actually delivered</label>
        <textarea id="delivered-learning" name="delivered_learning" rows="3"
            @if($session->delivered_learning === null && !session()->hasOldInput()) data-delivery-draft @endif>{{ old('delivered_learning', $session->delivered_learning ?? collect($deliveryDraft)->where('area', 'vocational')->pluck('label')->implode("\n")) }}</textarea>
        <p class="muted">Check that the note reflects eligible learning delivered in paid time. Exclude English and maths, breaks and other ineligible activity.</p>
    </details>
</section>
<script src="{{ asset('assets/register-training.js') }}?v=87" defer></script>

<section class="panel"><h2>Training delivered to the group</h2>
<p>Select approved topics delivered in full. Use the notes for other training or for part of a topic taught to the group.</p>
@if($trainingOptions)
<details @if(collect($structuredTraining['items'] ?? [])->contains(fn ($item) => !str_starts_with($item['subjectId'], 'custom:'))) open @endif>
    <summary>Topics from approved plans</summary>
    @php($selected = old('training') !== null ? old('training.subjects', []) : array_column($structuredTraining['items'] ?? [], 'subjectId'))
    @foreach(collect($trainingOptions)->groupBy('area') as $area => $subjects)
        <fieldset><legend>{{ \App\Support\RegisterTraining::AREAS[$area] }}</legend>
            @foreach($subjects as $subject)
                <label class="check"><input type="checkbox" name="training[subjects][]" value="{{ $subject['subjectId'] }}"
                    data-register-topic data-topic-label="{{ $subject['label'] }}"
                    @checked(in_array($subject['subjectId'], $selected ?? [], true))> {{ $subject['label'] }}</label>
            @endforeach
        </fieldset>
    @endforeach
</details>
@endif
<button type="button" data-use-planned-training hidden>Use planned vocational training</button>
<template id="planned-training-copy">{{ $session->planned_learning }}</template>
<div class="form-grid">
@foreach(\App\Support\RegisterTraining::AREAS as $area=>$label)
<label>{{ $label }}
<textarea name="training[{{ $area }}]" rows="3">{{ old("training.{$area}", collect($structuredTraining['items'] ?? [])->where('area',$area)->filter(fn ($item) => str_starts_with($item['subjectId'], 'custom:'))->pluck('label')->implode("\n")) }}</textarea>
</label>
@endforeach
</div></section>

<script src="{{ asset('assets/register-training.js') }}" defer></script>

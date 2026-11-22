@php($recorded = \App\Support\TaskPresentation::recordedActivities($answers))
@if($editing && ($steps = \App\Support\TaskPresentation::checklist($presentation)))
    @php($selected = old('activities_present') ? old('activities', []) : array_column($recorded, 'id'))
    <fieldset>
        <legend>Activities carried out</legend>
        <p>Tick the activities you completed. Record findings and measurements in the answer fields.</p>
        <input type="hidden" name="activities_present" value="1">
        @foreach($steps as $step)
            <label class="check">
                <input type="checkbox" name="activities[]" value="{{ $step['id'] }}" @checked(in_array($step['id'], (array) $selected, true))>
                {{ $step['label'] }}
            </label>
        @endforeach
    </fieldset>
@elseif(!$editing && $recorded)
    <h3>Activities recorded as completed</h3>
    <ul>@foreach($recorded as $step)<li>{{ $step['label'] }}</li>@endforeach</ul>
@elseif(!$editing && array_key_exists('_activities', $answers))
    <p>No activity checks were selected in this record.</p>
@endif

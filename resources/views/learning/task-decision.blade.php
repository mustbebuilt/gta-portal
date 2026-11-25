<section class="panel" id="task-decision">
    <h2>{{ $isKnowledge ? 'Record paper test result' : 'GTA tutor decision' }}</h2>
    <form method="post" action="{{ route('tasks.decide', $task) }}">
        @csrf
        <input type="hidden" name="version" value="{{ $task->version }}">
        @foreach($queue ?? [] as $key => $value)
            <input type="hidden" name="queue[{{ $key }}]" value="{{ $value }}">
        @endforeach
        @if($isKnowledge)
            <div class="form-grid">
                <div class="full"><label for="test-title">Paper test title</label><textarea id="test-title" name="test_title" rows="2" required>{{ old('test_title', $task->template['testTitleDefault'] ?? '') }}</textarea></div>
                <div><label for="marks-achieved">Marks achieved</label><input id="marks-achieved" name="marks_achieved" type="number" min="0" step="any" value="{{ old('marks_achieved') }}" required></div>
                <div><label for="marks-available">Marks available</label><input id="marks-available" name="marks_available" type="number" min="0.01" step="any" value="{{ old('marks_available') }}" required></div>
                <div class="full"><label for="test-evidence-reference">Paper evidence reference (optional)</label><input id="test-evidence-reference" name="test_evidence_reference" value="{{ old('test_evidence_reference') }}" maxlength="2000"></div>
            </div>
        @else
            @include('learning.fields', ['fields'=>collect($task->template['fields'])->whereIn('role',['trainer','observer']),'values'=>[],'readonly'=>false])
        @endif
        <div class="form-grid">
            <div><label for="decision">{{ $isKnowledge ? 'Outcome' : 'Whole-task decision' }}</label>
                <select id="decision" name="decision" required>
                    <option value="">Choose</option>
                    <option value="Approved" @selected(old('decision') === 'Approved')>{{ $isKnowledge ? 'Pass' : 'Approve task' }}</option>
                    <option value="Referred" @selected(old('decision') === 'Referred')>{{ $isKnowledge ? 'Refer' : 'Return for changes' }}</option>
                </select>
            </div>
            <div><label for="decision-date">Assessment date</label><input id="decision-date" name="decision_date" type="date" value="{{ old('decision_date', $markingFocus ? today()->toDateString() : null) }}" required max="{{ today()->toDateString() }}"></div>
            <div class="full"><label for="feedback">Feedback (required when returning work)</label><textarea id="feedback" name="feedback" rows="4">{{ old('feedback') }}</textarea></div>
        </div>
        <label class="check"><input name="confirmed" value="1" type="checkbox" @checked(old('confirmed')) required> I confirm this decision as {{ auth()->user()->name }}.</label>
        <div class="form-actions">
            <button name="after_save" value="stay" @class(['primary'=>$isKnowledge])>Save and stay</button>
            @unless($isKnowledge)<button name="after_save" value="next" class="primary">Save and open next</button>@endunless
        </div>
    </form>
</section>

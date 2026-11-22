<section class="panel">
    <h2>Training covered by this plan</h2>
    <p>Record the agreed tasks and subjects. Once approved, this scope sets the whole-programme totals in reviews and the required topics in the register.</p>
    @if(!$curriculumTasks)
        <p>Link a supported programme to select its curriculum.</p>
    @else
        @php($scope = $draft['curriculum'] ?? [])
        <form method="post" action="{{ route('delivery.curriculum', $apprentice) }}">
            @csrf
            <input type="hidden" name="version" value="{{ $row?->version ?? 0 }}">
            <label for="plan-task-scope">Apprenticeship tasks</label>
            <select id="plan-task-scope" name="task_scope" required>
                <option value="">Choose</option>
                <option value="all" @selected(old('task_scope',$scope['mode'] ?? null) === 'all')>All tasks in this programme</option>
                <option value="selected" @selected(old('task_scope',$scope['mode'] ?? null) === 'selected')>Selected tasks only</option>
            </select>
            <details>
                <summary>Choose individual tasks if needed</summary>
                @foreach(collect($curriculumTasks)->groupBy('block') as $block => $tasks)
                    <fieldset><legend>Block {{ $block }} · {{ $tasks->first()['theme'] }}</legend>
                    @foreach($tasks as $task)
                        <label class="check"><input type="checkbox" name="tasks[]" value="{{ $task['id'] }}"
                            @checked(in_array($task['id'], old('tasks', array_keys($scope['tasks'] ?? [])), true))> {{ $task['title'] }}</label>
                    @endforeach
                    </fieldset>
                @endforeach
            </details>
            @foreach(\App\Support\PlanCurriculum::SUBJECTS as $subject => $label)
                @php($entry = $scope['subjects'][$subject] ?? null)
                <fieldset>
                    <legend>{{ $label }}</legend>
                    <label for="scope-{{ $subject }}">Training requirement</label>
                    <select id="scope-{{ $subject }}" name="subjects[{{ $subject }}][scope]">
                        <option value="">Awaiting confirmation</option>
                        <option value="none" @selected(old('subjects.'.$subject.'.scope', $entry === null ? null : ($entry['topics'] ? 'selected' : 'none')) === 'none')>No training required</option>
                        <option value="selected" @selected(old('subjects.'.$subject.'.scope', $entry === null ? null : ($entry['topics'] ? 'selected' : 'none')) === 'selected')>Selected topics</option>
                    </select>
                    <details @if($entry['topics'] ?? []) open @endif>
                        <summary>Choose topics requiring training</summary>
                        @foreach($curriculumTopics[$subject] ?? [] as $id => $topic)
                            <label class="check"><input type="checkbox" name="subjects[{{ $subject }}][topics][]" value="{{ $id }}"
                                @checked(in_array($id, old('subjects.'.$subject.'.topics', array_keys($entry['topics'] ?? [])), true))> {{ $topic['level'] }} · {{ $topic['title'] }}</label>
                        @endforeach
                        <label for="scope-due-{{ $subject }}">Agreed training deadline (optional)</label>
                        <input id="scope-due-{{ $subject }}" type="date" name="subjects[{{ $subject }}][due]" value="{{ old('subjects.'.$subject.'.due', $entry['due'] ?? '') }}">
                    </details>
                </fieldset>
            @endforeach
            <button>Save draft curriculum</button>
        </form>
    @endif
</section>

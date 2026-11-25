@extends('layout')
@section('title', $task->title)
@section('content')
<header class="page-heading"><div><p class="eyebrow">{{ $apprentice->first_name }} {{ $apprentice->last_name }} · Block {{ $task->block }}</p><h1>{{ $task->title }}</h1><p>{{ $task->theme }}</p><span class="status {{ $task->status==='Approved'?'achieved':($task->markingOverdue()?'red':'neutral') }}">{{ $task->markingOverdue()?'Overdue marking':$task->status }}</span></div><div class="toolbar">
    @if($queue !== null)
        <a class="button" href="{{ route('marking.index', $queue) }}">Back to Work to mark</a>
    @else
        <a class="button" href="{{ route('learning.index', $apprentice) }}">Back to training tasks</a>
    @endif
    <details class="action-disclosure64">
        <summary>Print or download</summary>
        <ul>
            @if($task->template['kind'] !== 'source-pending')
                <li><a href="{{ route('tasks.recorded.pdf', $task) }}">Download recorded work PDF</a></li>
            @endif
            @if(str_ends_with($task->template['sourcePdfFilename'] ?? $task->template['filename'], '.pdf'))
                <li><a href="{{ route('tasks.pdf', $task) }}">Download original PDF</a></li>
            @endif
            <li><button type="button" data-print>Print task / Save PDF</button></li>
        </ul>
    </details>
</div></header>
@include('learning.errors')
@include('learning.next-step')
@if(!$release['open'])<p class="notice">{{ $release['label'] }}. Saved work remains available to read. An administrator can record an exception from the training-task list.</p>@endif
@if($planning->isNotEmpty())<section class="panel"><h2>Approved Block 9 planning guidance</h2><p class="muted">Use the existing answer fields for this guidance.</p>@foreach($planning as $activity)<p><strong>{{ $activity['code'] }}:</strong> {{ $activity['instruction'] }}</p>@endforeach</section>@endif
<details class="panel" @if($presentation || (isset($task->template['catalogueId']) && $task->template['catalogueId']!=='autocare'))open @endif><summary>Task instructions and source</summary><p class="recorded-answer">{{ $task->template['instructions'] }}</p><p class="muted">Source: {{ $task->template['filename'] }} · Version {{ $task->template['version'] }}. </p></details>
@if($presentation)
<details class="panel">
    <summary>Linked knowledge, skills and behaviours (KSBs)</summary>
    @if(filled($presentation['ksbSource']))
        <p class="recorded-answer">{{ $presentation['ksbSource'] }}</p>
    @else
        <p>No specific KSB links are listed in the task source.</p>
    @endif
    <p class="muted">Task links from the source. Formal KSB sign-off is recorded separately.</p>
</details>
@endif
@foreach($task->template['supplementaryPages']??[] as $sourcePage)<details class="panel"><summary>Scenario / source material · page {{ $sourcePage['page'] }}</summary><p class="recorded-answer">{{ $sourcePage['text'] }}</p></details>@endforeach
@if($tutor)<details class="panel"><summary>Task deadline</summary><form method="post" action="{{ route('tasks.deadline',$task) }}">@csrf<input type="hidden" name="version" value="{{ $task->version }}"><div class="search"><div><label for="due-date">Agreed deadline</label><input id="due-date" type="date" name="due_date" value="{{ $task->due_date?->format('Y-m-d') }}"></div><button>Save deadline</button></div><label for="deadline-reason">Reason for changing the date (optional)</label><textarea id="deadline-reason" name="reason" rows="2" maxlength="2000">{{ old('reason') }}</textarea></form><p>Changes are recorded; the approved delivery plan stays unchanged.</p></details>@endif
@if(\App\Support\LearningAccess::manager(auth()->user())||\App\Support\LearningAccess::tutor(auth()->user(),$apprentice))
<details class="panel"><summary>Deadline change history</summary><p>Recorded changes are visible to the main tutor and management.</p>
@forelse(array_reverse($task->deadline_history??[]) as $change)<article class="work-row"><div><p><strong>{{ $change['before']??'Not set' }} → {{ $change['after']??'Not set' }}</strong> · {{ $change['origin']==='approved_plan'?'Applied from approved delivery plan':'Individual task change' }}</p><p>{{ $change['at'] }} · {{ $change['by_name']??('Account '.$change['by']) }}</p>@if($change['reason'])<p class="recorded-answer">{{ $change['reason'] }}</p>@endif @if($change['origin']==='approved_plan')<p>Recorded plan {{ $change['source']['plan_id'] }} · approval reference <code class="recorded-answer">{{ $change['source']['approved_hash'] }}</code></p>@endif</div></article>@empty<p>No changes recorded in this history.</p>@endforelse</details>
@endif
@if($task->template['kind']==='source-pending')<p class="notice">Source required. This task cannot receive answers, evidence, a submission or an approval until its own programme source is loaded.</p>@endif
@php($fields = \App\Support\TaskPresentation::apprenticeFields($task, $presentation))
@php($isKnowledge = $task->template['kind'] === 'knowledge')
@php($isEditing = !$isKnowledge && $learner && $task->editable())
<section class="panel" id="task-work">
    <h2>{{ $task->status === 'Awaiting marking' ? 'Submitted apprentice work' : 'Apprentice work' }}</h2>
    @if($isKnowledge)
        <p>This assessment is completed on paper. The tutor records the result and feedback below.</p>
    @elseif($isEditing)
        <form method="post" enctype="multipart/form-data" action="{{ route('tasks.save', $task) }}">
            @csrf
            <input type="hidden" name="version" value="{{ $task->version }}">
            @include('learning.activity-checklist', ['answers'=>$currentAnswers, 'editing'=>true])
            @include('learning.fields', ['fields'=>$fields,'values'=>$currentAnswers,'readonly'=>false])
            <details class="panel" @if($errors->has('file') || $errors->has('files') || $errors->has('files.*') || $errors->has('description') || $errors->has('kind') || $errors->has('camera_photo') || $errors->has('camera_description')) open @endif>
                <summary>Add evidence files</summary>
                <label for="task-combined-file">Choose files (PDF, JPEG, PNG or DOCX). Up to five uploads per save, including any photo taken below. 15 MB each.</label>
                <input id="task-combined-file" data-evidence-file name="files[]" type="file" multiple accept=".pdf,.jpg,.jpeg,.png,.docx">
                <div data-file-details @if($errors->hasAny(['kind','description','files.*'])) data-keep-open @endif>
                <label for="task-combined-kind">Evidence type for these files</label>
                <select id="task-combined-kind" data-evidence-kind name="kind">
                    <option value="supporting-evidence" @selected(old('kind') === 'supporting-evidence')>Supporting evidence</option>
                    <option value="inspection-checklist" @selected(old('kind') === 'inspection-checklist')>Inspection checklist</option>
                    <option value="workplace-photo" @selected(old('kind') === 'workplace-photo')>Workplace photo</option>
                </select>
                <label for="task-photo-description">Evidence note (required for workplace photos)</label>
                <textarea id="task-photo-description" data-photo-description name="description" rows="3" maxlength="5000">{{ old('description') }}</textarea>
                </div>
                <label for="task-camera-photo">Take a workplace photo</label>
                <input id="task-camera-photo" name="camera_photo" type="file" accept="image/jpeg,image/png" capture="environment">
                <div data-camera-details @if($errors->hasAny(['camera_photo','camera_description'])) data-keep-open @endif>
                <label for="task-camera-description">What did you do, and how does this photo support the task?</label>
                <textarea id="task-camera-description" name="camera_description" rows="3" maxlength="5000">{{ old('camera_description') }}</textarea>
                </div>
                <button name="intent" value="upload">Save answers and add evidence</button>
            </details>
            <label class="check"><input type="checkbox" name="own_work" value="1" @checked(old('own_work'))> I confirm this is my own work.</label>
            <div class="form-actions">
                <button name="intent" value="draft">Save draft</button>
                <button name="intent" value="submit" class="primary">Submit to tutor</button>
            </div>
        </form>
    @else
        @include('learning.activity-checklist', ['answers'=>$currentAnswers, 'editing'=>false])
        @include('learning.fields', ['fields'=>$fields,'values'=>$currentAnswers,'readonly'=>true])
    @endif
    @if(!$isKnowledge || $currentEvidence->isNotEmpty())
        <h3>{{ $task->status === 'Awaiting marking' ? 'Evidence in this submission' : 'Saved evidence' }}</h3>
        <div class="work-list">
            @forelse($currentEvidence as $file)
                <div class="work-row">
                    <div><strong>{{ $file->name }}</strong>
                        <p class="muted">{{ ucfirst(str_replace('-', ' ', $file->kind)) }} · {{ number_format($file->bytes/1024) }} KB</p>
                        @if($file->description)<p class="recorded-answer">{{ $file->description }}</p>@endif
                    </div>
                    <a class="button" href="{{ route('tasks.evidence.download', [$task, $file]) }}">Download evidence<span class="sr-only"> {{ $file->name }}</span></a>
                </div>
            @empty
                <p>No evidence uploaded.</p>
            @endforelse
        </div>
    @endif
</section>
@if($tutor && ($task->status === 'Awaiting marking' || ($isKnowledge && $task->editable())))
    @include('learning.task-decision')
@endif
@include('learning.task-history')
<script src="{{ asset('assets/routine-workflows.js') }}?v=evidence-details" defer></script>
@endsection

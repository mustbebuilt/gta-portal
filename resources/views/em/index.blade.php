@extends('layout')
@section('title','Maths and English')
@section('content')
@include('quick-actions.context')
<header class="page-heading"><div><p class="eyebrow">{{ $apprentice->first_name }} {{ $apprentice->last_name }}</p><h1>Maths and English</h1><p>Training topics and qualification achievement are shown separately.</p></div>@if(auth()->user()->can('view',$apprentice))<a class="button" href="{{ route('apprentices.show',$apprentice) }}">Apprentice profile</a>@endif</header>
@include('learning.errors')
@include('em.next-action')
<p><a href="{{ route('em.caseload') }}">Back to Maths and English caseload</a></p>
@if(count($availableSubjects) > 1)
    <nav class="toolbar" aria-label="Choose a subject">
        <a @class(['button', 'primary' => !request()->filled('subject')]) @unless(request()->filled('subject')) aria-current="page" @endunless href="{{ route('em.index', $apprentice) }}">Both subjects</a>
        @foreach($availableSubjects as $available)
            <a @class(['button', 'primary' => request('subject') === $available]) @if(request('subject') === $available) aria-current="page" @endif href="{{ route('em.index', ['apprentice'=>$apprentice, 'subject'=>$available]) }}">{{ ucfirst($available) }}</a>
        @endforeach
    </nav>
@endif
<div class="dashboard-grid">@foreach($subjects as $subject)
@php($assigned=$topics->where('subject',$subject)->filter(fn($t)=>(float)$t->planned_hours>0))
@php($done=$assigned->where('status','Completed')->count())
<section class="panel"><h2>{{ ucfirst($subject) }} training</h2>@if($assigned->count())<div class="progress-summary"><div class="progress-ring" style="--progress:{{ round(100*$done/$assigned->count()) }};--progress-color:var(--achieved)"><strong>{{ round(100*$done/$assigned->count()) }}%</strong></div><div><p>{{ $done }} of {{ $assigned->count() }} assigned training topics completed.</p><p class="muted">{{ number_format($assigned->sum('planned_hours'),2) }} planned training hours. These are not achieved hours.</p></div></div>@else<p>No training topics with planned hours assigned. No completion percentage available.</p>@endif<p class="muted">{{ $topics->where('subject',$subject)->where('status','Secure')->count() }} zero-hour secure areas, excluded from training completion.</p>

@if(in_array($subject, $editableSubjects, true))
    <div class="toolbar">
        @unless(in_array($subject, $subjectsWithFunctionalSkillsRecord, true))
            <a class="button primary" href="{{ route('qualifications.create', ['apprentice'=>$apprentice, 'subject'=>$subject]) }}">Add {{ ucfirst($subject) }} training</a>
        @endunless
        <a href="#assigned-topics">Assigned topics</a>
        <a href="#em-qualification-results">Qualification results</a>
        <a href="#assessment-{{ $subject }}">Assessments and topic planning</a>
    </div>
@endif
</section>
@endforeach</div>
<section class="panel" id="em-qualification-results"><header class="page-heading"><div><h2>Qualification results</h2><p>Each qualification uses its own custom start and twelve-month target.</p></div>@if(collect($subjects)->contains(fn($s)=>\App\Support\LearningAccess::subject(auth()->user(),$apprentice,$s)))<a class="button primary" href="{{ route('qualifications.create', count($subjects) === 1 ? ['apprentice'=>$apprentice, 'subject'=>$subjects[0]] : $apprentice) }}">Add qualification</a>@endif</header>
<form class="search" method="get">@if(request('topics'))<input type="hidden" name="topics" value="{{ request('topics') }}">@endif
@if(request('subject'))<input type="hidden" name="subject" value="{{ request('subject') }}">@endif<div><label for="qualification-show">Show qualifications</label><select id="qualification-show" name="show">@foreach([''=>'All records','working'=>'Working towards','achieved'=>'Achieved / already held','overdue'=>'Overdue','today'=>'Due today','checks'=>'Date checks'] as $key=>$label)<option value="{{ $key }}" @selected(request('show','')===$key)>{{ $label }}</option>@endforeach</select></div><button>Apply filter</button></form>
<div class="work-list">@forelse($qualifications as $q)<article class="work-row"><div><h3>{{ ucfirst($q->subject) }} · {{ $q->title }}</h3><p>{{ $q->type }} · {{ $q->level }} · {{ $q->status }}</p><p><strong>Recorded result: {{ $q->result??'Not recorded' }}</strong></p><p class="muted">Custom start: {{ $q->start_date?->format('d M Y')??'Not set' }} · Target: {{ $q->target()?->format('d M Y')??'Not set' }} · Achieved: {{ $q->achievement_date?->format('d M Y')??'Not recorded' }}</p></div><span class="status {{ $q->achieved()?'achieved':($q->position()==='Overdue'?'red':'neutral') }}">{{ $q->position() }}</span>@include('em.qualification-files',['qualification'=>$q,'upload'=>false])@if(\App\Support\LearningAccess::subject(auth()->user(),$apprentice,$q->subject))<a class="button" href="{{ route('qualifications.edit',[$apprentice,$q]) }}">Update qualification / result</a>@endif</article>@empty<p>No matching qualification records. This does not mean a qualification is achieved or not required.</p>@endforelse</div></section>
<section class="panel" id="assigned-topics"><h2>Assigned training topics</h2><form class="search" method="get">@if(request('show'))<input type="hidden" name="show" value="{{ request('show') }}">@endif
@if(request('subject'))<input type="hidden" name="subject" value="{{ request('subject') }}">@endif<div><label for="topic-show">Show topic evidence</label><select id="topic-show" name="topics">@foreach([''=>'All topics','awaiting'=>'Awaiting review','referred'=>'Referred'] as $key=>$label)<option value="{{ $key }}" @selected(request('topics','')===$key)>{{ $label }}</option>@endforeach</select></div><button>Apply topic filter</button></form><p class="muted">Evidence filters do not change the training summary above. Accepted evidence does not itself complete training.</p><div class="work-list">@forelse($topicRows as $topic)<article class="work-row" id="topic-{{ $topic->id }}"><div><h3>{{ ucfirst($topic->subject) }} · {{ $topic->level }} · {{ $topic->topic }}</h3><p class="muted">{{ $topic->area }} · {{ $topic->planned_hours }} planned hours</p><p>Evidence: {{ $topic->evidence_stage }}</p></div><span class="status {{ $topic->status==='Completed'?'achieved':'neutral' }}">{{ $topic->status }}</span><div>
<a class="button" href="{{ route('topics.show', $topic) }}">{{ $topic->evidence_stage === 'Awaiting review' && in_array($topic->subject, $editableSubjects, true) ? 'Review evidence' : 'Open topic' }}</a>
@if(in_array($topic->subject, $editableSubjects, true) && (float) $topic->planned_hours > 0 && $topic->status !== 'Completed' && !in_array($topic->evidence_stage, ['Referred', 'Awaiting review'], true))
    @php($returning = (int) old('topic_row') === $topic->id)
    <details @if($returning) open @endif>
        <summary>Record topic completion</summary>
        <form method="post" action="{{ route('topics.update', $topic) }}">
            @csrf
            <input type="hidden" name="version" value="{{ $topic->version }}">
            <input type="hidden" name="status" value="Completed">
            <input type="hidden" name="topic_row" value="{{ $topic->id }}">
            <label>Actual completion date <input name="completion_date" type="date" max="{{ today()->toDateString() }}" value="{{ $returning ? old('completion_date') : '' }}" required></label>
            <label>Completion evidence or tutor note <textarea name="feedback" required>{{ $returning ? old('feedback') : '' }}</textarea></label>
            <button>Save completion</button>
        </form>
    </details>
@endif
</div></article>@empty<p>No matching assigned topics. This does not establish completion or absence of training need.</p>@endforelse</div></section>
@if($catalogue)
<details class="panel" id="em-topic-assignment" data-reveal-on-link @if($errors->hasAny(['catalogue_id', 'planned_hours'])) open @endif><summary>Assign a training topic</summary><p>Choose only the apprentice’s assessed training need. Enter zero only where secure. The catalogue currently contains source-confirmed topics only; missing catalogue areas have not been invented.</p><form method="post" action="{{ route('topics.assign',$apprentice) }}">@csrf<div class="form-grid"><div><label for="catalogue-id">Source topic</label><select id="catalogue-id" name="catalogue_id" required><option value="">Choose</option>@foreach(collect($catalogue)->groupBy('subject', true)->sortKeys() as $level=>$entries)<optgroup label="{{ $level }}">@foreach($entries->sortBy('topic') as $id=>$row)<option value="{{ $id }}" @selected((string)old('catalogue_id') === (string)$id)>{{ $row['topic'] }}</option>@endforeach</optgroup>@endforeach</select></div><div><label for="planned-hours">Individually planned training hours</label><input id="planned-hours" type="number" min="0" max="9999" step="0.01" name="planned_hours" value="{{ old('planned_hours') }}" required></div></div><button class="primary">Assign topic</button></form></details>
@endif
@foreach($editableSubjects as $fsSubject)
@php($restoringAssessment = old('subject') === $fsSubject && old('data.'.$fsSubject) !== null)
@php($savedAssessments = $entryRequirements?->context['functional_skills_setup82'] ?? [])
<details class="panel" id="assessment-{{ $fsSubject }}" data-reveal-on-link @if($restoringAssessment) open @endif>
<summary>{{ ucfirst($fsSubject) }} · Assessment information and follow-up</summary>
<form method="post" action="{{route('em.setup-assessments.save',$apprentice)}}">@csrf
<input type="hidden" name="subject" value="{{$fsSubject}}"><input type="hidden" name="version" value="{{$entryRequirements?->version??0}}">
@include('setup68.functional-skills',['fsInput'=>$restoringAssessment ? old('data') : array_replace(\App\Support\FunctionalSkillsSetup82::defaults(auth()->user()), $savedAssessments),'fsSubjects'=>[$fsSubject=>ucfirst($fsSubject)]])
<button class="primary">Save {{ucfirst($fsSubject)}} assessment information</button></form>
</details>
@endforeach
@if($editableSubjects)
<details class="panel" id="requirements-at-entry" data-reveal-on-link @if($errors->hasAny(['maths', 'english'])) open @endif>
    <summary>Functional Skills requirements at entry</summary>
    <p>Record whether each subject was required when the apprentice started. These answers feed the Retention report.</p>
    <form method="post" action="{{ route('em.requirements.save', $apprentice) }}">
        @csrf
        <input type="hidden" name="version" value="{{ $entryRequirements?->version ?? 0 }}">
        <div class="form-grid">
        @foreach($editableSubjects as $key)
            <div>
                <label for="requirement-{{ $key }}">{{ ucfirst($key) }} required at entry?</label>
                <select id="requirement-{{ $key }}" name="{{ $key }}">
                    @php($savedAnswer = $entryRequirements?->context[$key] ?? 'Unknown')
                    @foreach(\App\Models\ProgrammeOutcome::REQUIREMENT_CHOICES as $answer)
                        <option @selected(($errors->has('version') ? $savedAnswer : old($key, $savedAnswer)) === $answer)>{{ $answer }}</option>
                    @endforeach
                </select>
            </div>
        @endforeach
        </div>
        <button class="primary">Save requirements</button>
    </form>
</details>
@endif
@endsection
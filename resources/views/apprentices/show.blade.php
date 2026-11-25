@extends('layout')
@section('title', $apprentice->reference)
@section('content')
<header class="page-heading"><div><p class="eyebrow">{{ $apprentice->reference }}</p><h1>{{ $apprentice->first_name }} {{ $apprentice->last_name }}</h1><p class="muted">{{ $apprentice->programmeLabel() ?? 'Programme not recorded' }}</p></div><div class="toolbar"><a class="button" href="{{ route('apprentices.index') }}">Back to apprentices</a> @can('update', $apprentice)<a class="button" href="{{ route('apprentices.edit', $apprentice) }}">Edit apprentice</a>@endcan</div></header>
@include('apprentices.overview83')
<nav aria-label="Common apprentice actions" class="toolbar">
<a @class(['button','primary'=>!$overview83['next']]) href="{{ route('progress66.index',['apprentice'=>$apprentice->id]) }}">View progress</a>
<a class="button" href="{{ route('learning.index',$apprentice) }}">{{ auth()->user()->role==='apprentice'?'My training tasks':(auth()->user()->role==='tutor'?'Open training tasks':'Training tasks') }}</a>
<a class="button" href="{{ route('attendance.apprentice',$apprentice) }}">Attendance and OTJ</a>
@if(auth()->user()->role!=='apprentice' && ($overview83['next']['kind']??null)!=='review')<a class="button" href="{{ \App\Support\PortalActions::destination(auth()->user(),$apprentice,'reviews') }}">Progress reviews</a>@endif
</nav>
<details class="panel action-disclosure64"><summary>More record options</summary>
@include('apprentices.page-actions64')

</details>
<details class="panel"><summary>Apprentice details and staff contacts</summary>@if(\App\Support\LearningAccess::manager(auth()->user()))<a class="button" href="{{ route('apprentices.close-down', $apprentice) }}">Close down and archive</a>@endif<dl class="details-grid">
@php($approvedDateDetails = \App\Support\DeliveryPlan::approved($apprentice)['details'] ?? [])
@if(isset($approvedDateDetails['practical_end_date']))
<div><dt>Planned practical end</dt><dd>{{ $approvedDateDetails['practical_end_date'] }}</dd></div>
@endif
@foreach(['Learner reference' => $apprentice->reference, 'Email address' => $apprentice->email ?? 'Not recorded', 'First day in learning' => $apprentice->start_date?->format('d M Y') ?? 'Not recorded', ((isset($approvedDateDetails['apprenticeship_end_date'])) ? 'Planned apprenticeship end including EPA' : 'Planned training end date') => $apprentice->planned_end_date?->format('d M Y') ?? 'Not recorded', 'Employer' => $apprentice->employerLabel() ?? 'Not recorded', 'Tutor group' => $apprentice->groupLabel() ?? 'Not assigned', 'Main tutor' => $apprentice->tutor?->name ?? 'Not assigned', 'Learning & Progress Mentor' => $apprentice->mentor?->name ?? 'Not assigned', 'Careers adviser' => $apprentice->careersAdviser?->name ?? 'Not assigned', 'Maths tutor' => $apprentice->mathsTutor?->name ?? 'Not assigned', 'English tutor' => $apprentice->englishTutor?->name ?? 'Not assigned'] as $label => $value)<div><dt>{{ $label }}</dt><dd>{{ $value }}</dd></div>@endforeach
</dl><p class="muted">Last saved {{ $apprentice->updated_at->format('d M Y H:i') }} (UK time).</p></details>
@include('setup68.record-summary')
@include('apprentices.training-coverage')
@if($trainingReceipts ?? [])
<details class="panel"><summary>Training received · {{ count($trainingReceipts) }} records</summary>
<ul>@foreach($trainingReceipts as $training)
<li>{{ $training['date'] }} · {{ $training['group'] }} · {{ $training['label'] }} @if($training['coverage'] === 'partial') · Partly received @endif</li>
@endforeach</ul></details>
@endif
@if($latestDevelopment ?? null)
<details class="panel">
    <summary>Latest personal development snapshot · {{ $developmentMonth }}</summary>
    @include('scorecards.development-ratings', ['development' => $latestDevelopment])
    <a href="{{ route('scorecards.index', ['apprentice' => $apprentice, 'month' => $developmentMonth]) }}">View monthly history</a>
</details>
@endif


@endsection

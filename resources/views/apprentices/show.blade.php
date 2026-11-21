@extends('layout')
@section('title', $apprentice->reference)
@section('content')
<header class="page-heading"><div><p class="eyebrow">{{ $apprentice->reference }}</p><h1>{{ $apprentice->first_name }} {{ $apprentice->last_name }}</h1><p class="muted">{{ $apprentice->programmeLabel() ?? 'Programme not recorded' }}</p></div><div class="toolbar"><a class="button" href="{{ route('apprentices.index') }}">Back to apprentices</a>@can('update', $apprentice)<a class="button primary" href="{{ route('apprentices.edit', $apprentice) }}">Edit apprentice</a>@endcan</div></header>
@include('setup68.record-summary')
<nav aria-label="Common apprentice actions" class="toolbar">
<a class="button primary" href="{{ route('progress66.index',['apprentice'=>$apprentice->id]) }}">View progress</a>
<a class="button" href="{{ route('learning.index',$apprentice) }}">Training tasks</a>
<a class="button" href="{{ route('attendance.apprentice',$apprentice) }}">Attendance and OTJ</a>
@if(auth()->user()->role!=='apprentice')<a class="button" href="{{ route('reviews.create',$apprentice) }}">Progress reviews</a>@endif
</nav>
<details class="panel action-disclosure64"><summary>More record options</summary>
@include('apprentices.page-actions64')
@include('apprentices.next-steps')
</details>
<section class="panel"><h2>Apprentice details</h2><dl class="details-grid">
@php($approvedDateDetails = \App\Support\DeliveryPlan::approved($apprentice)['details'] ?? [])
@if(isset($approvedDateDetails['practical_end_date']))
<div><dt>Planned practical end</dt><dd>{{ $approvedDateDetails['practical_end_date'] }}</dd></div>
@endif
@foreach(['Learner reference' => $apprentice->reference, 'Email address' => $apprentice->email ?? 'Not recorded', 'First day in learning' => $apprentice->start_date?->format('d M Y') ?? 'Not recorded', ((isset($approvedDateDetails['apprenticeship_end_date'])) ? 'Planned apprenticeship end including EPA' : 'Planned training end date') => $apprentice->planned_end_date?->format('d M Y') ?? 'Not recorded', 'Employer' => $apprentice->employerLabel() ?? 'Not recorded', 'Tutor group' => $apprentice->groupLabel() ?? 'Not assigned', 'Main tutor' => $apprentice->tutor?->name ?? 'Not assigned', 'Learning & Progress Mentor' => $apprentice->mentor?->name ?? 'Not assigned', 'Careers adviser' => $apprentice->careersAdviser?->name ?? 'Not assigned', 'Maths tutor' => $apprentice->mathsTutor?->name ?? 'Not assigned', 'English tutor' => $apprentice->englishTutor?->name ?? 'Not assigned', 'Saved version' => $apprentice->version] as $label => $value)<div><dt>{{ $label }}</dt><dd>{{ $value }}</dd></div>@endforeach
</dl><p class="muted">Last saved {{ $apprentice->updated_at->format('d M Y H:i') }} (UK time).</p></section>
@if($latestDevelopment ?? null)
<details class="panel">
    <summary>Latest personal development snapshot · {{ $developmentMonth }}</summary>
    @include('scorecards.development-ratings', ['development' => $latestDevelopment])
    <a href="{{ route('scorecards.index', ['apprentice' => $apprentice, 'month' => $developmentMonth]) }}">View monthly history</a>
</details>
@endif

<section class="panel"><h2>Learning and progress</h2><p>Use View progress for the journey, or choose a task above. Specialist tools are under More record options. Training-task approval, assigned topic completion and qualification achievement are separate measures. No overall apprenticeship achievement percentage is inferred.</p></section>
@endsection

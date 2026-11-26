@extends('layout')
@section('title', $apprentice->reference)
@section('content')
<header class="page-heading">
    <div>
        <p class="eyebrow">{{ $apprentice->reference }}</p>
        <h1>{{ $apprentice->first_name }} {{ $apprentice->last_name }}</h1>
        <p>{{ $apprentice->programmeLabel() ?? 'Programme not recorded' }} · {{ $apprentice->groupLabel() ?? 'Group not assigned' }}</p>
        <p class="muted">{{ $apprentice->employerLabel() ?? 'Employer not recorded' }} · Main tutor {{ $apprentice->tutor?->name ?? 'not assigned' }}</p>
    </div>
    @if($workUrl = \App\Support\Workday83::returnUrl(request()))
        <a class="button" href="{{ $workUrl }}">{{ auth()->user()->role === 'apprentice' ? 'Back to your next steps' : 'Back to Work today' }}</a>
    @else
        <a class="button" href="{{ $listUrl }}">Back to apprentices</a>
    @endif
</header>
@if($next = $overview83['next'])
    <section class="panel" aria-labelledby="record-next">
        <div class="work-row">
            <div>
                <h2 id="record-next">Next action</h2>
                <p>{{ $next['title'] }} · {{ \App\Support\Workday83::STATUSES[$next['bucket']] }}</p>
            </div>
            <a class="button primary" href="{{ $next['url'] }}">Open next action</a>
        </div>
    </section>
@endif
@include('apprentices.overview83')
<nav aria-label="Common apprentice actions" class="toolbar">
    <a @class(['button','primary'=>!$next]) href="{{ $progressUrl }}">View progress</a>
    <a class="button" href="{{ route('journey.show', $apprentice) }}">Apprenticeship journey</a>
    <a class="button" href="{{ route('actions.index', $apprentice) }}">Action plan</a>
</nav>
<details class="panel action-disclosure64"><summary>More record options</summary>
@include('apprentices.page-actions64')

</details>
<details class="panel"><summary>Apprentice details and staff contacts</summary><dl class="details-grid">
@php($approvedDateDetails = $approvedPlan['details'] ?? [])
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

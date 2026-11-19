@extends('layout')
@section('title', $apprentice->reference)
@section('content')
<header class="page-heading"><div><p class="eyebrow">{{ $apprentice->reference }}</p><h1>{{ $apprentice->first_name }} {{ $apprentice->last_name }}</h1><p class="muted">{{ $apprentice->programmeLabel() ?? 'Programme not recorded' }}</p></div><a href="{{ route('apprentices.index') }}">Back to apprentices</a></header>
@include('setup68.record-summary')
@include('apprentices.page-actions64')
@include('apprentices.next-steps')
<section class="panel"><h2>Apprentice details</h2><dl class="details-grid">
@foreach(['Learner reference' => $apprentice->reference, 'Email address' => $apprentice->email ?? 'Not recorded', 'First day in learning' => $apprentice->start_date?->format('d M Y') ?? 'Not recorded', 'Planned training end date' => $apprentice->planned_end_date?->format('d M Y') ?? 'Not recorded', 'Employer' => $apprentice->employerLabel() ?? 'Not recorded', 'Tutor group' => $apprentice->groupLabel() ?? 'Not assigned', 'Main tutor' => $apprentice->tutor?->name ?? 'Not assigned', 'Learning & Progress Mentor' => $apprentice->mentor?->name ?? 'Not assigned', 'Careers adviser' => $apprentice->careersAdviser?->name ?? 'Not assigned', 'Maths tutor' => $apprentice->mathsTutor?->name ?? 'Not assigned', 'English tutor' => $apprentice->englishTutor?->name ?? 'Not assigned', 'Saved version' => $apprentice->version] as $label => $value)<div><dt>{{ $label }}</dt><dd>{{ $value }}</dd></div>@endforeach
</dl><p class="muted">Last saved {{ $apprentice->updated_at->format('d M Y H:i') }} (UK time).</p></section>

<section class="panel"><h2>Learning and progress</h2><p>Open a linked area above to view its saved records. Training-task approval, assigned topic completion and qualification achievement are separate measures. No overall apprenticeship achievement percentage is inferred.</p></section>
@endsection

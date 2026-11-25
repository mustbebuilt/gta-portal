@php
    $toReview = $topicRows->first(fn ($topic) => in_array($topic->subject, $editableSubjects, true) && $topic->evidence_stage === 'Awaiting review');
    $resultDue = $qualifications->first(fn ($record) => in_array($record->subject, $editableSubjects, true) && in_array($record->position(), ['Overdue', 'Due today'], true));
    $learning = $learner ? $topicRows->first(fn ($topic) => $topic->evidence_stage === 'Referred' && $topic->editable()) : null;
@endphp
@if($toReview || $resultDue || $learning)
    <section class="panel">
        <h2>Next action</h2>
        @if($toReview)
            <p>{{ ucfirst($toReview->subject) }} · {{ $toReview->topic }}</p>
            <a class="button primary" href="{{ route('topics.show', $toReview) }}">Review evidence</a>
        @elseif($resultDue)
            <p>{{ ucfirst($resultDue->subject) }} · {{ $resultDue->title }} · {{ $resultDue->position() }}</p>
            <a class="button primary" href="{{ route('qualifications.edit', [$apprentice, $resultDue]) }}">Update qualification result</a>
        @else
            <p>{{ ucfirst($learning->subject) }} · {{ $learning->topic }}</p>
            <a class="button primary" href="{{ route('topics.show', $learning) }}">Update your evidence</a>
        @endif
    </section>
@endif

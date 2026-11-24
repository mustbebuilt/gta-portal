<section class="panel">
    <h2>Submission and decision history</h2>
    @forelse(array_reverse($task->submissions ?? []) as $submission)
        @php($decision = $submission['decision'] ?? null)
        @php($sourceFields = $decision['source_fields'] ?? [])
        @php($submittedAt = $submission['submitted_at'] ?? null)
        <details>
            <summary>Version {{ $submission['version'] }} · {{ $decision ? ($isKnowledge ? ($decision['status'] === 'Approved' ? 'Pass' : 'Refer') : $decision['status']) : 'Awaiting marking' }}</summary>
            <p>{{ $submittedAt ? 'Submitted '.$submittedAt : ($isKnowledge ? 'Paper assessment record' : 'Submission date not recorded') }}</p>
            @if($decision)
                @if($isKnowledge && ($sourceFields['marks_available'] ?? 0) > 0)
                    <p><strong>Paper test score: {{ round(100 * $sourceFields['marks_achieved'] / $sourceFields['marks_available'], 1) }}%</strong></p>
                @endif
                <p>{{ $decision['tutor_name'] }} · {{ $decision['date'] }}</p>
                @if(filled($decision['feedback'] ?? null))<p class="recorded-answer">{{ $decision['feedback'] }}</p>@endif
                <dl class="details-grid">
                    @foreach($sourceFields as $key => $value)
                        @php($field = collect($task->template['fields'])->firstWhere('name', $key))
                        <div><dt>{{ $field['label'] ?? ucfirst(str_replace('_', ' ', $key)) }}</dt>
                            <dd>{{ ($field['type'] ?? null) === 'checkbox' && in_array($value, ['on','1',1,true], true) ? 'Confirmed' : ($value ?? 'Not recorded') }}</dd></div>
                    @endforeach
                </dl>
            @endif
            @unless($isKnowledge)
                <details>
                    <summary>Recorded answers</summary>
                    @include('learning.activity-checklist', ['answers'=>$submission['answers'] ?? [],'editing'=>false])
                    @include('learning.fields', ['fields'=>$fields,'values'=>$submission['answers'] ?? [],'readonly'=>true])
                </details>
                @if($submission['evidence'] ?? [])
                    <ul>
                        @foreach($submission['evidence'] as $item)
                            @php($file = $task->evidence->firstWhere('id', $item['id']))
                            <li>@if($file && $file->sha256 === ($item['sha256'] ?? null))
                                <a href="{{ route('tasks.evidence.download', [$task, $file]) }}">{{ $item['name'] }}</a>
                            @else
                                {{ $item['name'] }} · file unavailable
                            @endif</li>
                        @endforeach
                    </ul>
                @endif
            @endunless
        </details>
    @empty
        <p>No submission or decision recorded.</p>
    @endforelse
</section>

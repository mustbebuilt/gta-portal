<section class="panel" aria-labelledby="employer-meeting-title">
    <h2 id="employer-meeting-title">Employers to review</h2>
    <p>Kept at work · {{ $meeting['from'] }} to {{ $meeting['through'] }} · Two or more apprentices at the same employer.</p>
    @if(!$meeting['available'])
        <p>Ask an administrator to check the saved attendance registers before reviewing the employer list.</p>
    @else
        @forelse($meeting['items'] as $item)
            <details>
                <summary>{{ $item['employer']->name }} · {{ $item['employer']->reference }} · {{ $item['apprentices'] }} apprentices · {{ $item['sessions'] }} missed apprentice sessions</summary>
                <ul>
                @foreach($item['records'] as $record)
                    <li>{{ $record['date'] }} · <a href="{{ route('support.index', $record['apprentice']) }}">{{ $record['apprentice']->first_name }} {{ $record['apprentice']->last_name }}</a></li>
                @endforeach
                </ul>
                @if(\App\Support\LearningAccess::manager(auth()->user()))
                    <p><a href="{{ route('retention.employer-attendance', ['from' => $meeting['from'], 'to' => $meeting['through'], 'employer' => $item['id']]) }}#session-records">Open source registers</a></p>
                @endif
            </details>
        @empty
            <p>No employers meet this threshold within the records you can access.</p>
        @endforelse
    @endif
</section>

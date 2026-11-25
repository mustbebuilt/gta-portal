@php
    $first = $records->first();
    $unread = $records->contains(fn ($delivery) => !$delivery->seen_at);
    $source = $first->source;
@endphp
<article class="panel">
    <h3>{{ \Carbon\CarbonImmutable::parse($date)->format('j M Y') }} · {{ $unread ? 'Unread' : 'Read' }}</h3>
    @if(\App\Support\ReviewedImports::hash($source) === $first->source_hash && ($source['apprentice_id'] ?? null) === $apprentice->id && ($source['recipient_id'] ?? null) === auth()->id())
        <ul>
            @foreach(collect($source['items'])->unique(fn ($item) => [$item['kind'], $item['id'] ?? null, $item['date'] ?? null]) as $item)
                <li><a href="{{ \App\Support\ApprenticeReminderSources::link($apprentice, $item) }}">{{ \App\Support\ApprenticeReminderSources::LABELS[$item['kind']] ?? 'Open portal item' }}</a>
                    @if(!empty($item['date'])) · {{ \Carbon\CarbonImmutable::parse($item['date'])->format('j M Y') }}@endif
                </li>
            @endforeach
        </ul>
    @else
        <p>Saved source needs checking. Open My next step for current work.</p>
    @endif
    @if($unread)
        <form method="post" action="{{ route('apprentice-reminders.seen', $first) }}">
            @csrf<button>Mark this saved digest read</button>
        </form>
    @endif
    <details><summary>Email and text delivery</summary>
        @foreach($records as $delivery)<p>{{ $delivery->channel === 'sms' ? 'Text' : 'Email' }} · {{ $delivery->state }}</p>@endforeach
        <p>Accepted means the provider accepted the attempt. It gives no receipt or read confirmation.</p>
    </details>
</article>

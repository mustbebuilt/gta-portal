@php
    $person = $row['apprentice'];
    $primary = $row['reasons'][0];
    $others = collect(array_slice($row['reasons'], 1))->groupBy('url');
    $primaryKey = \App\Support\OperationalFollowups::sourceKey($person, $primary);
    $hasPrimaryTools = $openFollowups->has($primaryKey) || ($ownersBySource[$primaryKey] ?? collect())->isNotEmpty();
    $showTools = old('source_key') && collect($row['reasons'])->contains(
        fn ($reasonItem) => \App\Support\OperationalFollowups::sourceKey($person, $reasonItem) === old('source_key')
    );
@endphp
<section class="panel">
    <h2>{{ $person->first_name }} {{ $person->last_name }}</h2>
    <p>{{ $person->reference }}</p>
    <p><strong>{{ ucfirst($primary['bucket']) }} · {{ ucfirst($primary['kind']) }}</strong> · {{ $primary['text'] }} @if($primary['date']) · {{ $primary['date'] }}@endif</p>
    <p><a class="button primary" href="{{ $primary['url'] }}">Open {{ $primary['kind'] }} record</a></p>
    @if($others->isNotEmpty() || $hasPrimaryTools)
        <details @if($showTools) open @endif>
            <summary>{{ $others->isNotEmpty() ? 'Other reasons and follow-up' : 'Staff follow-up' }}</summary>
            @include('staff-workflow.start-followup', ['item' => $primary])
            @foreach($others as $url => $items)
                <ul>
                    @foreach($items as $item)
                        <li>
                            <strong>{{ ucfirst($item['bucket']) }} · {{ ucfirst($item['kind']) }}</strong> · {{ $item['text'] }} @if($item['date']) · {{ $item['date'] }}@endif
                            @include('staff-workflow.start-followup', ['item' => $item])
                        </li>
                    @endforeach
                </ul>
                @if($url !== $primary['url'])
                    <p><a href="{{ $url }}">Open {{ $items->first()['kind'] }} record</a></p>
                @endif
            @endforeach
        </details>
    @endif
</section>

@php
    $peak = max(1, max(array_column($items, 1)));
@endphp
<section class="panel report-headline" aria-label="{{ $title }}">
    <h2>{{ $title }}</h2>
    @isset($basis)<p>{{ $basis }}</p>@endisset
    <div aria-hidden="true" class="report-bars">
        @foreach($items as [$label, $value])
            <div>
                <span>{{ $label }}</span>
                <span class="report-bar-track"><span style="--bar:{{ 100 * ($value ?? 0) / $peak }}%"></span></span>
                <strong>{{ $value ?? '—' }}</strong>
            </div>
        @endforeach
    </div>
    <table class="report-headline-table">
        <caption>Exact figures</caption>
        <thead><tr><th scope="col">Measure</th><th scope="col">Count</th><th scope="col">Status</th></tr></thead>
        <tbody>
            @foreach($items as $item)
                @php($rating = \App\Support\ReportThresholds::internal($item[1], $item[2] ?? null))
                <tr><th scope="row">{{ $item[0] }}</th><td>{{ $item[1] ?? 'Unknown' }}</td>
                    <td><span class="report-rag report-rag-{{ $rating['status'] }}">{{ $rating['label'] }}</span></td></tr>
            @endforeach
        </tbody>
    </table>
    @if(collect($items)->contains(fn ($item) => isset($item[2])))
        <details><summary>GTA thresholds</summary><p>{{ \App\Support\ReportThresholds::internal(null, 'exceptions')['rule'] }}</p></details>
    @endif
</section>

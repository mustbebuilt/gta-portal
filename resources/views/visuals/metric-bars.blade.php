<div class="report-measures-bars">
    @foreach(array_slice($chart['rows'], 0, 14) as $row)
        <div class="report-measure-row">
            <span>{{ $row['label'] }}</span>
            <span class="report-measure-track" aria-hidden="true">
                @if($row['value'] !== null)
                    <span @class(['report-measure-reference' => !empty($row['comparison'])]) style="--measure-width: {{ 100 * $row['value'] / $chart['max'] }}%"></span>
                @endif
            </span>
            <strong>{{ $row['value'] === null ? 'Unknown' : $row['value'] }}</strong>
        </div>
    @endforeach
    <p class="muted">Scale 0–{{ $chart['max'] }} · {{ $chart['unit'] }}</p>
</div>

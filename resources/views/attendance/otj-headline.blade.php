@php($hours = fn ($value) => $value === null ? 'Not available' : number_format($value, 2).' hours')
<dl class="details-grid">
    <div><dt>{{ $recordedLabel ?? 'Recorded off-the-job training' }}</dt><dd>{{ $hours($otjHeadline['recorded_hours']) }}</dd></div>
    <div><dt>Indicative target to date</dt><dd>{{ $otjHeadline['expected_hours'] === null ? 'Target to date unavailable' : $hours($otjHeadline['expected_hours']) }}</dd></div>
    <div><dt>Gap to date</dt><dd>{{ $hours($otjHeadline['gap_hours']) }}{{ ($otjHeadline['gap_hours'] ?? 0) > 0 ? ' below pace' : '' }}</dd></div>
    <div><dt>Total approved target</dt><dd>{{ $hours($otjHeadline['target_hours']) }}</dd></div>
</dl>
@if($otjHeadline['needs_check'])<p>Some records are awaiting confirmation or checking.</p>@endif

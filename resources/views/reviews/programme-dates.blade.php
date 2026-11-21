@if(isset($snapshot['programme_dates']))
@php($position = $snapshot['otj_position'] ?? [])
<h3>Agreed programme dates and OTJ position</h3>
<dl class="details-grid">
    @foreach(['start' => 'Apprenticeship start date', 'practical_end' => 'Expected practical finish date', 'apprenticeship_end' => 'Expected apprenticeship end date'] as $key => $label)
        <div><dt>{{ $label }}</dt><dd>{{ $snapshot['programme_dates'][$key] ?? 'Not recorded' }}</dd></div>
    @endforeach
    <div><dt>Agreed OTJ target</dt><dd>{{ isset($position['target_hours']) ? number_format($position['target_hours'], 2).' hours' : 'No current approved target' }}</dd></div>
    @if(isset($position['recorded_minutes']))
        <div><dt>Recorded eligible OTJ</dt><dd>{{ number_format($position['recorded_minutes'] / 60, 2) }} hours</dd></div>
    @endif
    @if(isset($position['progressive_expected_hours']))
        <div><dt>Expected by {{ $position['date'] }} (indicative)</dt><dd>{{ number_format($position['progressive_expected_hours'], 2) }} hours</dd></div>
        <div><dt>OTJ pace</dt><dd>{{ $position['progressive_gap_hours'] > 0 ? number_format($position['progressive_gap_hours'], 2).' hours below pace' : 'On or above pace' }}</dd></div>
    @endif
</dl>
@if(isset($position['progressive_expected_hours']))
<p class="muted">{{ $position['progressive_basis'] }} Check pending records before acting on a shortfall.</p>
@endif
@endif

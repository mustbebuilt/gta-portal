@if(isset($snapshot['programme_dates']))
@php($position = $snapshot['otj_position'] ?? [])
<h3>Agreed programme dates and OTJ position</h3>
<dl class="details-grid">
    @foreach(['start' => 'Apprenticeship start date', 'practical_end' => 'Expected practical finish date', 'apprenticeship_end' => 'Expected apprenticeship end date'] as $key => $label)
        <div><dt>{{ $label }}</dt><dd>{{ $snapshot['programme_dates'][$key] ?? 'Not recorded' }}</dd></div>
    @endforeach
</dl>
@include('attendance.otj-headline', ['otjHeadline' => \App\Support\OtjPosition::summaryHeadline($position)])
@if(isset($position['progressive_expected_hours']))
<p class="muted">{{ $position['progressive_basis'] }} Check pending records before acting on a shortfall.</p>
@endif
@endif

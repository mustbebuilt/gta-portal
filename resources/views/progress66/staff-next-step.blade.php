@php
    $role = $report['role'];
    $actionKind = match ($role) {
        'tutor' => 'marking',
        'employer' => 'request',
        'mentor' => 'review',
        default => null,
    };
    $action = $actionKind ? collect($report['actions'])->firstWhere('kind', $actionKind) : null;
    $checkMetric = collect($report['metrics'])->firstWhere('key', 'checks');
    $registerMetric = collect($report['metrics'])->firstWhere('key', 'registers');
@endphp

@if($action)
    <section class="p66-next" aria-labelledby="p66-staff-next">
        <h2 id="p66-staff-next">{{ match ($role) { 'tutor' => 'Next to mark', 'employer' => 'Employer action to respond to', default => 'Next review to check' } }}</h2>
        <p><strong>{{ $action['title'] }}</strong> · {{ $action['name'] }}</p>
        @if($action['due'])
            <p>{{ $action['overdue'] ? 'Overdue · ' : 'Due ' }}<time datetime="{{ $action['due'] }}">{{ \Carbon\Carbon::parse($action['due'])->format('j M Y') }}</time></p>
        @endif
        <a class="button primary" href="{{ $action['url'] }}">{{ match ($role) { 'tutor' => 'Open submission', 'employer' => 'Open employer request', default => 'Open review' } }}</a>
    </section>
@elseif($role === 'admin' && (($checkMetric['value'] ?? 0) || ($registerMetric['value'] ?? 0)))
    <section class="p66-next" aria-labelledby="p66-staff-next">
        <h2 id="p66-staff-next">Records to check</h2>
        <div class="toolbar">
            @if($checkMetric['value'] ?? 0)
                <a class="button" href="{{ $link(['metric' => 'checks', 'page' => 1]) }}#records">Check {{ $checkMetric['value'] }} apprentice {{ \Illuminate\Support\Str::plural('record', $checkMetric['value']) }}</a>
            @endif
            @if($registerMetric['value'] ?? 0)
                <a class="button" href="#registers">Check {{ $registerMetric['value'] }} {{ \Illuminate\Support\Str::plural('register', $registerMetric['value']) }}</a>
            @endif
        </div>
    </section>
@endif

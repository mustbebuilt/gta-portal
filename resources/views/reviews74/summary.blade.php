@extends('layout')
@section('title', 'Progress Review Summary — '.$apprentice->reference)
@section('content')
<header class="page-heading">
    <div>
        <p class="eyebrow">{{ $apprentice->reference }} · Apprentice Progress Review (Pack 74)</p>
        <h1>{{ $apprentice->first_name }} {{ $apprentice->last_name }} — Progress Review</h1>
        <p class="muted">{{ $apprentice->programmeLabel() ?? 'Programme not recorded' }} · {{ $period['draft_only'] ? 'Draft summary (not a held meeting or signature)' : 'Review period '.$period['from'].' to '.$period['to'] }}</p>
    </div>
    <div class="toolbar">
        <a class="button" href="{{ route('apprentices.show', $apprentice) }}">Back to profile</a>
        @if(auth()->user()->role !== 'apprentice')<a class="button primary" href="{{ route('reviews.create', $apprentice) }}">Open formal review workflow</a>@endif
        <button type="button" data-print>Print / Save PDF</button>
    </div>
</header>

<section class="panel">
    <h2>Programme &amp; review dates</h2>
    <dl class="details-grid">
        <div><dt>Apprenticeship start</dt><dd>{{ $dates['start'] }}</dd></div>
        <div><dt>Expected practical finish</dt><dd>{{ $dates['practical_end'] }}</dd></div>
        <div><dt>Expected apprenticeship finish (incl. EPA)</dt><dd>{{ $dates['apprenticeship_end'] }}</dd></div>
        <div><dt>Actual review date</dt><dd>{{ $dates['actual_review'] }}</dd></div>
        <div><dt>Next review due (10-week cycle)</dt><dd>{{ $dates['next_due'] }}</dd></div>
        <div><dt>Period covered</dt><dd>{{ $period['from'] }} to {{ $period['to'] }}</dd></div>
    </dl>
</section>

<section class="panel">
    <h2>Learning &amp; task completion</h2>
    <p class="muted">Learning and task completion only — not overall competence or qualification achievement. Missing denominators show Not available.</p>
    <div class="table-scroll">
        <table>
            <thead><tr><th>Area</th><th>Assigned completed</th><th>Whole personal plan</th></tr></thead>
            <tbody>
                @foreach(['tasks' => 'Apprenticeship tasks', 'english' => 'Functional Skills English', 'maths' => 'Functional Skills Maths'] as $key => $label)
                @php($p = $progress[$key])
                <tr>
                    <td><strong>{{ $label }}</strong></td>
                    <td>{{ $p['assigned_percent'] !== null ? $p['assigned_done'].' / '.$p['assigned_total'].' ('.$p['assigned_percent'].'%)' : 'Not available' }}</td>
                    <td>{{ $p['overall_percent'] !== null ? $p['overall_done'].' / '.$p['overall_total'].' ('.$p['overall_percent'].'%)' : 'Not available' }}</td>
                </tr>
                @endforeach
            </tbody>
        </table>
    </div>
</section>

<section class="panel">
    <h2>Training delivered in this period (across all groups)</h2>
    @foreach(['training' => 'Vocational training', 'fs' => 'Functional Skills', 'themes' => 'Cross-cutting themes (EDI, British Values, Prevent, Safeguarding)'] as $area => $label)
        <h3>{{ $label }}</h3>
        @forelse($delivered[$area] as $item)
            <p>{{ $item['date'] }} · {{ $item['group'] }} · <strong>{{ $item['label'] }}</strong> ({{ $item['coverage'] === 'partial' ? 'Part delivered' : 'Delivered' }})</p>
        @empty
            <p class="muted">None recorded in this period.</p>
        @endforelse
    @endforeach
    @if($legacy_sessions)<p class="muted">{{ count($legacy_sessions) }} attended legacy session(s) exist without structured subject mapping.</p>@endif
</section>

<section class="panel">
    <h2>Tasks requiring attention</h2>
    <p><strong>Overdue:</strong> {{ count($tasks['overdue']) }} · <strong>Due before next review:</strong> {{ count($tasks['due_before_next']) }} · <strong>Missing due date:</strong> {{ count($tasks['undated']) }}</p>
    @foreach(['overdue' => 'Overdue tasks', 'due_before_next' => 'Due before next review', 'undated' => 'Tasks missing a due date'] as $bucket => $heading)
        @if($tasks[$bucket])
        <h3>{{ $heading }}</h3>
        <ul>@foreach($tasks[$bucket] as $t)<li>{{ $t['title'] }} ({{ $t['due'] ?? 'No due date' }})</li>@endforeach</ul>
        @endif
    @endforeach
</section>

<section class="panel">
    <h2>Monthly personal development snapshots</h2>
    <div class="table-scroll">
        <table>
            <thead><tr><th>Month</th><th>Status</th><th>Ratings / summary</th></tr></thead>
            <tbody>
            @foreach($months as $m)
                <tr>
                    <td><strong>{{ $m['month'] }}</strong></td>
                    <td><span class="status">{{ $m['state'] }}</span></td>
                    <td>
                        @if($m['state'] === 'Recorded')
                            @foreach($areas as $k => $lbl)<span>{{ $lbl }}: <strong>{{ $m['record']['ratings'][$k] ?? 'Not observed' }}</strong></span>@if(!$loop->last) · @endif @endforeach
                            @if(!empty($m['record']['comment']))<p class="muted">{{ $m['record']['comment'] }}</p>@endif
                        @elseif($m['state'] === 'Legacy scale')
                            <span class="muted">Legacy numeric scorecard retained separately.</span>
                        @else
                            <span class="muted">Not recorded</span>
                        @endif
                    </td>
                </tr>
            @endforeach
            </tbody>
        </table>
    </div>
</section>
@endsection

@extends('layout')
@section('title', 'Training journey — '.$apprentice->reference)
@section('content')
<header class="page-heading">
    <div>
        <p class="eyebrow">{{ $apprentice->reference }} · Personal training bank</p>
        <h1>{{ $apprentice->first_name }} {{ $apprentice->last_name }} — Training journey</h1>
        <p class="muted">{{ $apprentice->programmeLabel() ?? 'Programme not recorded' }} · Derived across all attended sessions and groups.</p>
    </div>
    <div class="toolbar">
        <a class="button" href="{{ route('apprentices.show', $apprentice) }}">Back to apprentice</a>
        <a class="button" href="{{ route('attendance.apprentice', $apprentice) }}">Attendance and OTJ</a>
    </div>
</header>

<section class="panel">
    <h2>Coverage summary</h2>
    <dl class="details-grid">
        <div><dt>Delivered</dt><dd>{{ $counts['delivered'] }}</dd></div>
        <div><dt>Overdue</dt><dd>{{ $counts['overdue'] }}</dd></div>
        <div><dt>Part delivered</dt><dd>{{ $counts['partial'] }}</dd></div>
        <div><dt>Due today</dt><dd>{{ $counts['due'] }}</dd></div>
        <div><dt>Planned / unscheduled</dt><dd>{{ $counts['planned'] + $counts['unscheduled'] }}</dd></div>
        <div><dt>Banked receipts</dt><dd>{{ count($receipts) }}</dd></div>
    </dl>
    @if($stale_sessions)
    <p class="errors"><strong>Reconciliation needed:</strong> {{ count($stale_sessions) }} attended session(s) had their attendance register edited after structured delivery was recorded. Re-confirm those registers to restore coverage.</p>
    @endif
    @if($legacy_sessions)
    <p class="notice"><strong>Legacy sessions present:</strong> {{ count($legacy_sessions) }} attended session(s) pre-date structured subject recording and are listed below as unmapped evidence. Gap counts above are provisional until mapped.</p>
    @endif
</section>

<section class="panel">
    <h2>Required programme subjects</h2>
    <div class="table-scroll">
        <table>
            <thead><tr><th>Subject</th><th>Planned due</th><th>Status</th><th>Recorded sessions</th></tr></thead>
            <tbody>
            @forelse($journey as $item)
                <tr>
                    <td><strong>{{ $item['label'] }}</strong></td>
                    <td>{{ $item['due'] ?? 'Not scheduled' }}</td>
                    <td><span class="status">{{ ucfirst($item['state']) }}</span></td>
                    <td>
                        @forelse($item['records'] as $rec)
                            <div>{{ $rec['date'] }} · {{ $rec['group'] }} ({{ $rec['coverage'] }})</div>
                        @empty
                            <span class="muted">None recorded</span>
                        @endforelse
                    </td>
                </tr>
            @empty
                <tr><td colspan="4">No programme subjects available.</td></tr>
            @endforelse
            </tbody>
        </table>
    </div>
</section>

<section class="panel">
    <h2>All banked delivery receipts (cross-group)</h2>
    <div class="table-scroll">
        <table>
            <thead><tr><th>Date</th><th>Group</th><th>Area</th><th>Subject</th><th>Coverage</th></tr></thead>
            <tbody>
            @forelse($receipts as $r)
                <tr>
                    <td>{{ $r['date'] }}</td>
                    <td>{{ $r['group'] }}</td>
                    <td>{{ ucfirst(str_replace('_', ' ', $r['area'] ?? 'vocational')) }}</td>
                    <td>{{ $r['label'] ?? $r['subjectId'] }}</td>
                    <td>{{ $r['coverage'] === 'partial' ? 'Part delivered' : 'Delivered' }}</td>
                </tr>
            @empty
                <tr><td colspan="5">No structured training receipts recorded yet.</td></tr>
            @endforelse
            </tbody>
        </table>
    </div>
</section>

@if($legacy_sessions)
<details class="panel"><summary>Legacy attended sessions without structured subjects ({{ count($legacy_sessions) }})</summary>
    <ul>
    @foreach($legacy_sessions as $ls)
        <li><strong>{{ $ls['date'] }}</strong> · {{ $ls['group'] }} · {{ $ls['name'] }} @if($ls['delivered_learning'])— {{ $ls['delivered_learning'] }}@endif</li>
    @endforeach
    </ul>
</details>
@endif
@endsection

@php
    ['attendance' => $attendance, 'otj' => $otj, 'block' => $block, 'allApproved' => $allApproved] = $progress;
    $otjTone = match (true) {
        $otj['needs_check'], $otj['recorded_hours'] === null, $otj['expected_hours'] === null => '',
        $otj['gap_hours'] <= 0 => 'support-progress-on-track',
        $otj['gap_hours'] >= $otj['expected_hours'] * .1 => 'support-progress-behind',
        default => 'support-progress-warning',
    };
@endphp
<section class="panel" aria-labelledby="support-progress-heading">
    <h2 id="support-progress-heading">Progress at a glance</h2>
    <dl class="support-progress">
        <div><dt>Attendance · last 12 weeks</dt>
            <dd>
                @if($attendance === null) Check register records
                @elseif($attendance['unmarked']) Attendance marks need checking
                @elseif($attendance['percent'] === null) No sessions recorded
                @else <strong>{{ round($attendance['percent'], 1) }}%</strong> ({{ $attendance['attended'] }} of {{ $attendance['expected'] }} sessions)
                @endif
            </dd>
        </div>
        <div class="{{ $otjTone }}"><dt>Off-the-job training</dt>
            <dd>
                @if($otj['recorded_hours'] === null) No hours recorded
                @else {{ $otj['recorded_hours'] }} hours recorded
                @endif
                @if($otj['expected_hours'] !== null) · {{ $otj['expected_hours'] }} hours due @endif
                @if($otj['needs_check'])<small>Hours need checking</small>
                @elseif($otj['gap_hours'] > 0)<small>{{ $otj['gap_hours'] }} hours behind target</small>
                @elseif($otjTone === 'support-progress-on-track')<small>On target</small>
                @endif
            </dd>
        </div>
        <div><dt>{{ $block ? 'Next incomplete block · '.$block['number'] : 'Training block tasks' }}</dt>
            <dd>{{ $block ? $block['approved'].' of '.$block['allocated'].' tasks approved' : ($allApproved ? 'All allocated tasks approved' : 'No block tasks allocated') }}</dd>
        </div>
    </dl>
</section>

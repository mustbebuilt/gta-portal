@php
    $issues = $review['issues'];
    $calculation = $review['calculation'];
    $canConfirm = in_array($draft->state, ['Draft', 'Ready'], true);
    $dates = $review['dates69'] ?? [];
    $draftEditable = $draft->state === 'Draft';
    $group = $finalCheck['group'];
    $tutor = $finalCheck['tutor'];
    $skills = $finalCheck['skills'];
    $needsDateCheck = collect($issues)->contains(fn ($issue) => $issue['step'] === 3) || !empty($calculation['unresolved']);
@endphp

<section class="panel" aria-labelledby="setup-check-title">
    <h2 id="setup-check-title">Check the setup</h2>
    @if($issues && $canConfirm)
        <div class="warn">
            <h3>Fix before confirming</h3>
            <ul>
                @foreach($issues as $issue)
                    <li>
                        @if($draft->state === 'Draft')
                            <a href="{{ route('setup68.show', [$draft, $issue['step']]).(isset($issue['field']) ? '#'.$issue['field'] : '') }}">
                                {{ $issue['message'] }}
                            </a>
                        @else
                            {{ $issue['message'] }}
                        @endif
                    </li>
                @endforeach
            </ul>
            @if($draft->state === 'Ready')<a href="#reopen-setup">Reopen setup to make corrections</a>@endif
        </div>
    @endif

    @if($calculation)
        <p><strong>{{ number_format($calculation['revised_hours'], 2) }} hours</strong>
            {{ $calculation['unresolved'] ? '· Provisional OTJ hours' : '· Planned off-the-job training' }}</p>
    @endif

    <dl class="setup-grid">
        <div>
            <dt>Apprenticeship</dt>
            <dd>{{ $review['template']?->template['name'] ?? 'Not selected' }} @if($review['template']) · {{ $review['template']->template['standard_code'] }} v{{ $review['template']->template['standard_version'] }}@endif</dd>
        </div>
        <div>
            <dt>Employer</dt>
            <dd>{{ $review['employer']?->name ?? $p['employer']['name'] ?? 'Not selected' }} @if($draftEditable)<a href="{{ route('setup68.show', [$draft, 5]) }}#employer-search">Edit employer</a>@endif</dd>
        </div>
        <div>
            <dt>Apprentice</dt>
            <dd>{{ $p['details']['first_name'] ?? 'Not entered' }} {{ $p['details']['last_name'] ?? '' }} · {{ $p['details']['reference'] ?? '' }}</dd>
        </div>
        <div><dt>First day in learning</dt><dd>{{ $p['programme']['start_date'] ?? 'Not entered' }}</dd></div>
        <div>
            <dt>Tutor group</dt><dd>{{ $group?->name ?? 'Not assigned' }} @if($group && !$group->active) · Inactive @endif
                @if($draftEditable)<a href="{{ route('setup68.show', [$draft, 3]) }}#group">Edit group</a>@endif</dd>
        </div>
        <div>
            <dt>Main tutor</dt><dd>{{ $tutor?->name ?? 'Not assigned' }} @if($tutor && !$tutor->active) · Inactive @endif
                @if($draftEditable)<a href="{{ route('setup68.show', [$draft, 3]) }}#tutor">Edit tutor</a>@endif</dd>
        </div>
        <div>
            <dt>Maths and English</dt>
            <dd>
                @foreach(['maths'=>'Maths', 'english'=>'English'] as $key => $label)
                    @php($state = $skills[$key]['state'] ?? null)
                    <div>{{ $label }} · {{ match($state) { 'recorded' => 'Assessments recorded', 'pending' => 'Follow-up planned', default => 'Assessment status to check' } }}</div>
                    @if($state === 'pending' && filled($skills[$key]['next_action'] ?? null))
                        <p>{{ $skills[$key]['next_action'] }}</p>
                    @endif
                @endforeach
                <a href="{{ route('setup68.show', [$draft, 6]) }}">Check assessments and follow-up</a>
            </dd>
        </div>
        <div>
            <dt>Prior learning</dt>
            <dd>{{ match($p['rpl']['mode'] ?? null) { 'none'=>'No relevant prior learning', 'applies'=>($calculation['adjusted_count'] ?? 0).' adjusted KSBs', default=>'Decision needed' } }}
                @if($draftEditable)<a href="{{ route('setup68.show', [$draft, 4]) }}#rpl-mode">Amend in Step 4</a>@endif</dd>
        </div>
    </dl>

    @if($canConfirm)
        <form method="post" action="{{ route('setup68.confirm', $draft) }}">
            @csrf
            <input type="hidden" name="version" value="{{ $draft->version }}">
            <input type="hidden" name="confirmed" value="1">
            @if($dates)
                <h3>Planned end dates</h3>
                <p>Check the planned dates. You can amend them to reflect agreed delivery.</p>
                @if(!empty($dates['stale']))
                    <p class="warn">The programme, start date or prior learning changed. Check these dates against the earlier saved dates of {{ $dates['previous']['practical_end'] }} and {{ $dates['previous']['programme_end'] }}.</p>
                @endif
                <div class="setup-grid">
                    <div>
                        <label for="step6-practical-end">Planned practical end date</label>
                        <input id="step6-practical-end" type="date" name="practical_end" value="{{ old('practical_end', $dates['selected']['practical_end'] ?? '') }}" required>
                    </div>
                    <div>
                        <label for="step6-programme-end">Planned apprenticeship end date (including assessment)</label>
                        <input id="step6-programme-end" type="date" name="programme_end" value="{{ old('programme_end', $dates['selected']['programme_end'] ?? '') }}" required>
                    </div>
                </div>
                @if(!empty($dates['saved_source']))<p>Reference: {{ $dates['saved_source'] }}</p>@endif
            @endif
            <div class="actions">
                <button class="primary" name="intent" value="confirm" @disabled(count($issues) > 0)>{{ $draft->apprentice_id ? 'Confirm corrections' : 'Confirm and create apprentice' }}</button>
                @if($draftEditable && $dates && !$needsDateCheck)
                    <button name="intent" value="save_dates">Save dates to draft</button>
                @endif
            </div>
        </form>
    @elseif($dates)
        <dl class="setup-grid">
            <div><dt>Planned practical end date</dt><dd>{{ $dates['selected']['practical_end'] ?? 'Not recorded' }}</dd></div>
            <div><dt>Planned apprenticeship end date</dt><dd>{{ $dates['selected']['programme_end'] ?? 'Not recorded' }}</dd></div>
        </dl>
    @endif

    <details>
        <summary>Inclusion setup status</summary>
        <p>{{ match($p['markers']['state'] ?? null) { 'selected'=>'Markers recorded', 'none'=>'None apply', 'pending'=>'Pending', default=>'Not recorded' } }} · Authorised support access only.</p>
    </details>
    @if($calculation)
        <details>
            <summary>Training hours by block</summary>
            <div class="table-wrap">
                <table>
                    <thead><tr><th scope="col">Block</th><th scope="col">Original OTJ</th><th scope="col">Removed</th><th scope="col">Revised OTJ</th><th scope="col">Allocation</th></tr></thead>
                    <tbody>
                        @foreach(\App\Support\Rpl68::reviewBlocks($calculation) as $block)
                            <tr>
                                <th scope="row">{{ $block['number'] }}. {{ $block['title'] }}</th>
                                <td>{{ number_format($block['original_hours'], 2) }}</td>
                                <td>{{ number_format($block['removed_hours'], 2) }}</td>
                                <td>{{ $block['unresolved'] ? 'Pending allocation' : number_format($block['hours'], 2) }}</td>
                                <td>{{ $block['unresolved'] ? implode(', ', $block['unresolved']) : 'Resolved' }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </details>
    @endif
</section>

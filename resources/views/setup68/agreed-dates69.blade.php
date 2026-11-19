@php($dateRecord = $p['plan_dates69'] ?? [])
<section class="panel">
    <h2>Training Plan dates</h2>
    <dl>
        @if($review['dates69']['proposed'])
        <dt>Calculated practical end</dt><dd>{{ $review['dates69']['proposed']['practical_end'] }}</dd>
        <dt>Calculated apprenticeship end including EPA</dt><dd>{{ $review['dates69']['proposed']['programme_end'] }}</dd>
        @endif
        @if(isset($dateRecord['practical_end'], $dateRecord['programme_end']))
            <dt>Agreed core OTJ hours</dt><dd>{{ $dateRecord['agreed_hours'] }}</dd>
            <dt>Training Plan reference</dt><dd>{{ $dateRecord['source'] }}</dd>
            <dt>Agreed practical end</dt><dd>{{ $dateRecord['practical_end'] }}</dd>
            <dt>Agreed apprenticeship end</dt><dd>{{ $dateRecord['programme_end'] }}</dd>
        @endif
    </dl>
    @if($review['dates69']['issue'])
        <p class="warn">{{ $review['dates69']['issue'] }}</p>
    @endif
    @if($step === 6 && $review['dates69']['approved'])
        <div class="table-wrap">
            <table>
                <thead><tr><th>Approved schedule from the Training Plan</th><th>Start</th><th>End</th></tr></thead>
                <tbody>
                    @foreach($review['dates69']['approved']['blocks'] as $block)
                        <tr><td>{{ $block['number'] }}. {{ $block['title'] }}</td><td>{{ $block['start'] }}</td><td>{{ $block['end'] }}</td></tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif
    @if($step === 4 && $draft->state === 'Draft')
        <form method="post" action="{{ route('setup68.save', [$draft, 4]) }}">
            @csrf
            <input type="hidden" name="version" value="{{ $draft->version }}">
            <label for="agreed-plan-source">Agreed Training Plan reference and revision</label>
            <input id="agreed-plan-source" name="data[source]" required maxlength="250" value="{{ old('data.source', $dateRecord['source'] ?? '') }}">
            <label for="agreed-hours">Agreed core OTJ hours after RPL</label>
            <input id="agreed-hours" name="data[agreed_hours]" type="number" step="any" min="0.01" max="10000" required value="{{ old('data.agreed_hours', $dateRecord['agreed_hours'] ?? '') }}">
            <div class="setup-grid">
                @foreach(['practical_end' => 'Agreed practical end', 'programme_end' => 'Agreed apprenticeship end', 'pause_start' => 'Approved pause start', 'pause_end' => 'Approved pause end'] as $field => $label)
                    <div>
                        <label for="agreed-{{ $field }}">{{ $label }}</label>
                        <input type="date" id="agreed-{{ $field }}" name="data[{{ $field }}]" value="{{ old('data.'.$field, $dateRecord[$field] ?? '') }}" @required(str_ends_with($field, '_end') && $field !== 'pause_end')>
                    </div>
                @endforeach
            </div>
            <label for="agreed-block-schedule">Approved block schedule if different from the template</label>
            <textarea id="agreed-block-schedule" name="data[schedule]" rows="4" maxlength="1000">{{ old('data.schedule', $dateRecord['schedule'] ?? '') }}</textarea>
            <p>Paste one line per block with start and end separated by a comma, using YYYY-MM-DD. The portal checks the sequence against the agreed end dates.</p>
            <button name="action" value="save_plan_dates">Save agreed dates</button>
        </form>
    @endif
</section>

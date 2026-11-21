<details class="panel" id="monthly-outstanding" @if($monthlyOutstanding->isNotEmpty()) open @endif>
    <summary>Monthly development · {{ today()->format('F Y') }} · {{ $monthlyOutstanding->count() }} outstanding</summary>
    @forelse($monthlyOutstanding as $row)
        @php($apprentice = $row['apprentice'])
        <article class="work-row">
            <div>
                <strong>{{ $apprentice->first_name }} {{ $apprentice->last_name }}</strong>
                <p>{{ $row['group'] }}</p>
            </div>
            @if($row['session'])
                <a class="button" href="{{ route('attendance.show', ['session' => $row['session'], 'snapshot_for' => $apprentice->id]).'#monthly-development-'.$apprentice->id }}">
                    Open snapshot · {{ $row['session']->session_date->format('j M') }}
                </a>
            @else
                <p class="muted">No attended register available this month</p>
            @endif
        </article>
    @empty
        <p>No outstanding monthly snapshots in these groups.</p>
    @endforelse
</details>

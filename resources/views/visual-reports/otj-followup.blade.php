<section class="panel" id="otj-followup">
    <h2>OTJ follow-up</h2>
    <p class="muted">Check any pending records and the planned training schedule before agreeing catch-up.</p>
    @if(empty($report['otj_followup']))
        <p>No current OTJ shortfalls or open catch-up actions in this report.</p>
    @else
        <div class="table-scroll">
            <table>
                <thead><tr><th>Apprentice</th><th>Expected by today</th><th>Confirmed</th><th>Gap</th><th>Next action</th></tr></thead>
                <tbody>
                    @foreach($report['otj_followup'] as $row)
                        @php($checkRecords = $row['otj_pending'] > 0 || $row['otj_checks'] > 0)
                        <tr>
                            <td><strong>{{ $row['name'] }}</strong><br>{{ $row['reference'] }}</td>
                            <td>{{ isset($row['progressive_expected_hours']) ? number_format($row['progressive_expected_hours'], 2).' hrs' : 'Unavailable' }}</td>
                            <td>{{ number_format($row['recorded_hours'], 2) }} hrs</td>
                            <td>{{ isset($row['progressive_gap_hours']) ? number_format($row['progressive_gap_hours'], 2).' hrs' : 'Unavailable' }}</td>
                            <td>
                                @if($row['catchup'])
                                    <a href="{{ route('actions.index', $row['id']).'#action-'.$row['catchup']['id'] }}">View existing action</a>
                                    <br>{{ $row['catchup']['owner'] }} · Due {{ $row['catchup']['due'] ?? 'Not set' }}
                                @elseif(!$checkRecords)
                                    <a href="{{ route('actions.create', ['apprentice' => $row['id'], 'otj' => 1]) }}">Agree catch-up</a>
                                @endif
                                @if($checkRecords)
                                    <p><a href="{{ route('otj.show', $row['id']) }}">Check pending OTJ records</a><br>{{ $row['otj_pending'] }} pending · {{ $row['otj_checks'] }} to check</p>
                                @elseif(!$row['catchup'])
                                    <br><a href="{{ route('otj.show', $row['id']) }}">View OTJ ledger</a>
                                @endif
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif
</section>

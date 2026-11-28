@php($attendanceCounts = $chart['key'] === 'attendance-by-programme')
<table>
    <caption>{{ $chart['title'] }} — exact values ({{ $chart['unit'] }})</caption>
    <thead><tr>
        <th scope="col">Measure</th>
        <th scope="col">{{ $chart['first'] ?? 'Recorded value' }}</th>
        @if($chart['type'] === 'grouped')<th scope="col">{{ $chart['second'] }}</th>@endif
        @if($attendanceCounts)<th scope="col">Attended</th><th scope="col">Expected</th><th scope="col">Unmarked</th>@endif
    </tr></thead>
    <tbody>
        @forelse($chart['rows'] as $row)
            <tr>
                <th scope="row">{{ $row['label'] }}</th>
                <td>{{ $row['value'] ?? 'Unknown / not established' }}</td>
                @if($chart['type'] === 'grouped')<td>{{ $row['second'] ?? 'Unknown / not established' }}</td>@endif
                @if($attendanceCounts)
                    <td>{{ $row['attended'] ?? 'Unknown' }}</td>
                    <td>{{ $row['expected'] ?? 'Unknown' }}</td>
                    <td>{{ $row['unmarked'] ?? 'Unknown' }}</td>
                @endif
            </tr>
        @empty
            <tr><td colspan="{{ $attendanceCounts ? 5 : ($chart['type'] === 'grouped' ? 3 : 2) }}">No source records in this scope.</td></tr>
        @endforelse
    </tbody>
</table>

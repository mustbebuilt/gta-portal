@extends('layout')
@section('title', 'Apprentice archive')
@section('content')
<header class="page-heading">
    <div><h1>Apprentice archive</h1><p>Programmes archived after a confirmed achievement.</p></div>
    <a class="button" href="{{ route('apprentices.index') }}">Back to apprentices</a>
</header>
<section class="panel">
    <div class="table-scroll"><table>
        <thead><tr><th scope="col">Apprentice</th><th scope="col">Programme</th><th scope="col">Grade</th><th scope="col">EPA attempt</th><th scope="col">Achievement date</th></tr></thead>
        <tbody>
        @forelse($records as $record)
            @php($outcome = $outcomes->get($record->id)->context)
            <tr>
                <td><a href="{{ route('apprentices.close-down', $record) }}">{{ $record->first_name }} {{ $record->last_name }} · {{ $record->reference }}</a></td>
                <td>{{ $record->programmeLabel() ?? 'Not recorded' }}</td>
                <td>{{ $outcome['epa_closure']['grade'] }}</td><td>{{ $outcome['epa_closure']['attempt'] }}</td><td>{{ $outcome['achievementDate'] }}</td>
            </tr>
        @empty
            <tr><td colspan="5">No archived achievements.</td></tr>
        @endforelse
        </tbody>
    </table></div>
    <div class="pagination">
        @if($records->previousPageUrl())<a class="button" href="{{ $records->previousPageUrl() }}">Previous page</a>@endif
        <span>Page {{ $records->currentPage() }} of {{ $records->lastPage() }}</span>
        @if($records->nextPageUrl())<a class="button" href="{{ $records->nextPageUrl() }}">Next page</a>@endif
    </div>
</section>
@endsection

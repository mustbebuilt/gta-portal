<h3>Monthly tutor snapshots</h3>
@forelse($snapshot['monthly_snapshots'] ?? [] as $monthly)
    @if($monthly['missing'] ?? false)
        <p><strong>{{ $monthly['month'] }}</strong> · Not recorded</p>
    @else
        <details>
            <summary>{{ $monthly['month'] }} · Recorded</summary>
            @if(isset($monthly['development']))
                @include('scorecards.development-ratings', ['development' => $monthly['development']])
            @else
                @foreach(\App\Http\Controllers\MonthlyScorecardController::AREAS as $key => $label)
                    @isset($monthly['scores'][$key])
                        <p>{{ $label }} · {{ $monthly['scores'][$key] }} / 5</p>
                    @endisset
                @endforeach
                <p>{{ $monthly['comment'] ?? 'No comment recorded' }}</p>
            @endif
        </details>
    @endif
@empty
    <p>No monthly tutor snapshots in this period.</p>
@endforelse

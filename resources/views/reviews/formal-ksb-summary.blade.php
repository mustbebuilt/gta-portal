@if(array_key_exists('formal_ksb_results', $snapshot))
    <details>
        <summary>Formal knowledge, skills and behaviours assessments</summary>
        @if($snapshot['formal_ksb_results'] === null)
            <p>An approved programme mapping is needed to record formal results.</p>
        @else
            <p>Formal results recorded by the review date. Task completion is shown separately.</p>
            <ul>
            @forelse($snapshot['formal_ksb_results'] as $result)
                <li>{{ $result['code'] }} · {{ $result['outcome'] }} · {{ $result['date'] }}</li>
            @empty
                <li>No formal assessment results recorded yet.</li>
            @endforelse
            </ul>
        @endif
        @if(!$review->exists || $review->state === 'Draft')
            @if(\App\Support\LearningAccess::manager(auth()->user()) || \App\Support\LearningAccess::tutor(auth()->user(), $apprentice))
                <p><a href="{{ route('reviews.ksb.index', $apprentice) }}">Record or check formal KSB results</a></p>
            @endif
        @endif
    </details>
@endif

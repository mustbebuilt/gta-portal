<p>{{ $prep['current_plan'] ? 'Current approved plan available' : 'Approved delivery plan needs checking' }}.</p>
@if($prep['draft_id'])
    <details>
        <summary>{{ count($prep['pending']) }} saved preparation item(s) to check</summary>
        @if($prep['pending'])
            <ul>@foreach($prep['pending'] as $label)<li>{{ $label }}</li>@endforeach</ul>
        @else
            <p>The saved checklist is complete.</p>
        @endif
        <p>{{ $prep['captures'] }} preparation captures</p>
        <a href="{{ route('review-briefs.index', [$a, $prep['draft_id']]) }}">Capture or refresh proposed notes</a>
    </details>
@endif

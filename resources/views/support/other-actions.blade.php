<details class="panel">
    <summary>Show other actions</summary>
    @foreach(['planned'=>'Planned', 'completed'=>'Completed'] as $key => $label)
        <h3>{{ $label }}</h3>
        @forelse($otherActions[$key] as $action)
            <p><strong>{{ $action->title }}</strong> · {{ $action->owner }}
                @if($key === 'planned' && $action->deadline()) · Due {{ $action->deadline()->format('j M Y') }} @endif
                @if($key === 'completed' && $action->completion_date) · {{ $action->completion_date->format('j M Y') }} @endif
            </p>
        @empty
            <p>No {{ strtolower($label) }} actions.</p>
        @endforelse
    @endforeach
    <a class="button" href="{{ route('actions.index', $apprentice) }}">View apprentice actions</a>
</details>

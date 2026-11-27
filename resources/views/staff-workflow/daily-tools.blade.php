<nav aria-label="Daily work" class="toolbar">
    @if(in_array(auth()->user()->role, ['admin', 'ceo', 'tutor'], true))
        <a class="button" href="{{ $nextRegister ? route('attendance.show', $nextRegister['session']) : route('attendance.index') }}">
            {{ $nextRegister ? 'Open next register' : 'Apprentice registers' }}
        </a>
        <a class="button" href="{{ route('reviews.index', ['view' => 'upcoming']) }}">Upcoming reviews</a>
    @endif
    @if(\App\Support\SavedPortalWork::allowed(auth()->user()))
        <a class="button" href="{{ route('saved-work.index') }}">Continue a draft</a>
    @endif
    <details>
        <summary>More work lists</summary>
        <ul>
            <li><a href="{{ route('operations-desk.index', ['q' => $q]) }}">Recorded staff follow-ups</a></li>
            @if(in_array(auth()->user()->role, ['admin', 'ceo', 'tutor'], true))
                <li><a href="{{ route('dashboard.index') }}">All listed work</a></li>
            @endif
        </ul>
    </details>
</nav>

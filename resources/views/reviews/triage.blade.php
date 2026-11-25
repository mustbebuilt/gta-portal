<section class="panel" aria-labelledby="review-attention">
    <h2 id="review-attention">Reviews needing attention</h2>
    <div class="toolbar">
        @foreach(['meeting-overdue'=>'Meeting overdue','meeting-soon'=>'Due today or within 14 days','awaiting-signoff'=>'Held · awaiting sign-off'] as $key => $label)
            <a class="button {{ $reviewView === $key ? 'primary' : '' }}" href="{{ route('reviews.index', ['view' => $key] + request()->only('group','apprentice','search')) }}" @if($reviewView === $key) aria-current="page" @endif>
                {{ $label }} · {{ $triageCounts[$key] }}
            </a>
        @endforeach
        @if($triageView)
            <a class="button" href="{{ route('reviews.index', request()->only('group','apprentice','search')) }}">All in scope</a>
        @endif
    </div>

</section>

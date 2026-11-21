<section class="panel">
    @forelse($reviewRows as $row)
        @php($apprentice = $row['apprentice'])
        @php($item = $row['triage'])
        <article class="work-row">
            <div>
                <h3>{{ $apprentice->first_name }} {{ $apprentice->last_name }} · {{ $apprentice->reference }}</h3>
                <p>Mentor: {{ $apprentice->mentor?->name ?? 'Not assigned' }} · Tutor: {{ $apprentice->tutor?->name ?? 'Not assigned' }}</p>
                @if($reviewView === 'awaiting-signoff')
                    <p>Meeting recorded {{ $item['held'] }}</p>
                @else
                    <p>{{ $item['date_provisional'] ? 'Provisional next meeting reference' : 'Tracked meeting due' }} {{ $item['due'] }}</p>
                    @if($item['booking_id'])<p>Booked for {{ $item['booking_at']->format('d M Y H:i') }}</p>@endif
                @endif
            </div>
            @if($reviewView === 'awaiting-signoff' && $item['ready_id'])
                @if($item['unchecked'])
                    <a class="button primary" href="{{ route('reviews.signed.form', $item['ready_id']) }}">Check signed copy</a>
                @else
                    <a class="button primary" href="{{ route('reviews.edit', [$apprentice, $item['ready_id']]) }}">Open review sign-off</a>
                @endif
            @elseif($reviewView === 'awaiting-signoff')
                <div class="toolbar">
                    <a class="button primary" href="{{ $row['latest'] ? route('reviews.edit', [$apprentice, $row['latest']]) : route('reviews.create', $apprentice) }}">Prepare review for sign-off</a>
                    <a href="{{ route('review-appointments.show', $item['held_appointment_id']) }}">Open reported meeting</a>
                </div>
            @elseif($item['booking_id'])
                <a class="button primary" href="{{ route('review-appointments.show', $item['booking_id']) }}">Open booked meeting</a>
            @else
                <a class="button primary" href="{{ route('review-appointments.form', $apprentice) }}">Arrange review meeting</a>
            @endif
        </article>
    @empty
        <p>No apprentices match this view.</p>
    @endforelse
</section>

<section class="panel" aria-labelledby="next-register-title">
    <h2 id="next-register-title">Your next register</h2>
    @if($nextRegister83)
        <p><strong>{{ $nextRegister83['session']->group->name }}</strong> · {{ $nextRegister83['session']->session_date->format('D j M Y') }} · {{ $nextRegister83['session']->name }}</p>
        <p>{{ $nextRegister83['missing_codes'] }} attendance codes to record.</p>
        <a class="button primary" href="{{ route('attendance.show', ['session' => $nextRegister83['session'], ...$nextScope83]) }}">Open next register</a>
    @elseif($scheduledThisWeek->isEmpty())
        <p>No register needs opening in this view. Check the weekly schedule or earlier sessions below.</p>
    @endif
    @if($scheduledThisWeek->isNotEmpty())
        @if($nextRegister83)<details><summary>Other registers scheduled this week</summary>@endif
        @foreach($scheduledThisWeek as $row)
            <form class="work-row" method="post" action="{{ route('attendance.weekly.open', $row['group']) }}">
                @csrf
                <input type="hidden" name="date" value="{{ $row['occurrence']['date'] }}">
                <input type="hidden" name="week" value="{{ $row['occurrence']['week'] }}">
                <p><strong>{{ $row['group']->name }}</strong> · {{ \Carbon\CarbonImmutable::parse($row['occurrence']['date'])->format('D j M Y') }}</p>
                <button @class(['primary' => !$nextRegister83 && $loop->first])>Open weekly register<span class="sr-only"> for {{ $row['group']->name }}</span></button>
            </form>
        @endforeach
        @if($nextRegister83)</details>@endif
    @endif
</section>

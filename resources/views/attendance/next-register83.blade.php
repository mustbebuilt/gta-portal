<section class="panel" aria-labelledby="next-register-title">
    <h2 id="next-register-title">Your next register</h2>
    @if($nextRegister83)
        <p><strong>{{ $nextRegister83['session']->group->name }}</strong> · {{ $nextRegister83['session']->session_date->format('D j M Y') }} · {{ $nextRegister83['session']->name }}</p>
        <p>{{ $nextRegister83['missing_codes'] }} attendance codes to record.</p>
        <a class="button primary" href="{{ route('attendance.show', ['session' => $nextRegister83['session'], ...$nextScope83]) }}">{{ $nextRegister83['session']->regular_week === now()->startOfWeek()->toDateString() ? "Open this week’s register" : "Open next register" }}</a>
    @elseif($scheduledThisWeek->isEmpty())
        <p>No register needs opening in this view. Check the weekly schedule or earlier sessions below.</p>
    @endif
    @if($scheduledThisWeek->isNotEmpty())
        @if($nextRegister83)<details><summary>Other registers scheduled this week</summary>@endif
        @foreach($scheduledThisWeek as $row)
            <div class="work-row">
                <p><strong>{{ $row['group']->name }}</strong> · {{ \Carbon\CarbonImmutable::parse($row['occurrence']['date'])->format('D j M Y') }}</p>
                @include('attendance.open-weekly', ['row' => $row['occurrence'] + ['group' => $row['group']], 'registerUrl' => null])
            </div>
        @endforeach
        @if($nextRegister83)</details>@endif
    @endif
</section>

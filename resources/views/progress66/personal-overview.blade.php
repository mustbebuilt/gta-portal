@php
    $next = $report['demo'] ? ['title' => $p['next_title'], 'label' => 'View fictional task record', 'url' => $p['next_url'], 'due' => null] : ($p['next_step'] ?? null);
    $waiting = $p['task_status']['Awaiting marking'] ?? 0;
    $otj = $p['otj'];
    $attendance = $p['attendance'];
    $hours = fn ($value) => rtrim(rtrim(number_format($value, 2, '.', ','), '0'), '.');
    $variance = $otj['recorded_hours'] !== null && $otj['planned_due_hours'] !== null
        ? round($otj['recorded_hours'] - $otj['planned_due_hours'], 2) : null;
    $reportDate = fn ($date) => \Carbon\Carbon::parse($date)->format('j M Y');
@endphp

<section id="next-step" class="p66-next" aria-labelledby="p66-next-title">
    <h2 id="p66-next-title">My next step</h2>
    @if($next)
        <h3>{{ $next['title'] }}</h3>
        @if($next['due'])
            <p>Due <time datetime="{{ $next['due'] }}">{{ $reportDate($next['due']) }}</time></p>
        @endif
        <a class="button primary" href="{{ $next['url'] }}">{{ $next['label'] }}</a>
    @elseif(!$waiting)
        <p>No action is ready for you right now.</p>
    @endif
    @if($waiting)
        <p><strong>With your tutor for marking</strong> · {{ $waiting }} {{ \Illuminate\Support\Str::plural('task', $waiting) }}</p>
    @endif
</section>

<section class="p66-personal-summary" aria-labelledby="p66-personal-headlines">
    <h2 id="p66-personal-headlines">At a glance</h2>
    <div class="p66-personal-grid">
        <article class="p66-progress-card">
            <h3>Training tasks</h3>
            @if($p['known_tasks'] > 0)
                <p><strong>{{ $p['approved'] }} of {{ $p['known_tasks'] }}</strong> assigned tasks approved</p>
                <progress value="{{ $p['approved'] }}" max="{{ $p['known_tasks'] }}" aria-label="Approved assigned tasks"></progress>
            @else
                <p>Assigned task progress is not available.</p>
            @endif
            @if(!empty($p['programme_tasks']['total']))
                <p>{{ $p['programme_tasks']['approved'] }} of {{ $p['programme_tasks']['total'] }} tasks approved across the full programme.</p>
            @endif
            @if($pending = $p['task_status']['Source needs checking'] ?? 0)
                <p class="p66-note">Source checks needed for {{ $pending }} {{ \Illuminate\Support\Str::plural('task', $pending) }}.</p>
            @endif
        </article>

        <article class="p66-progress-card">
            <h3>Off-the-job training</h3>
            @if(isset($p['otj_headline']))
                @include('attendance.otj-headline', ['otjHeadline' => $p['otj_headline']])
            @else
            <p><strong>{{ $otj['recorded_hours'] === null ? 'Not available' : $hours($otj['recorded_hours']).' hours recorded' }}</strong></p>
            <p>{{ $otj['planned_due_hours'] === null ? 'Target to date unavailable' : $hours($otj['planned_due_hours']).' hours planned for blocks already due' }}</p>
            @if($variance !== null)
                <p>{{ $variance === 0.0 ? 'Recorded hours match the plan to date.' : $hours(abs($variance)).' hours '.($variance < 0 ? 'below' : 'above').' the plan to date.' }}</p>
            @endif
            @if($otj['target_hours'] !== null)
                <p class="p66-note">{{ $hours($otj['target_hours']) }} hours planned in total.</p>
            @endif
            @if(($otj['pending_sources'] ?? 0) || ($otj['invalid_sources'] ?? 0))
                <p class="p66-note">Some training hours are awaiting confirmation or checking.</p>
            @endif
            @endif
        </article>

        <article class="p66-progress-card">
            <h3>Attendance</h3>
            @if($attendance['expected'])
                <p><strong>{{ $attendance['attended'] }} of {{ $attendance['expected'] }}</strong> expected sessions attended</p>
                @if($attendance['unmarked'])
                    <p>Attendance marks pending for {{ $attendance['unmarked'] }} {{ \Illuminate\Support\Str::plural('session', $attendance['unmarked']) }}.</p>
                @endif
            @else
                <p>Attendance is not available for this period.</p>
            @endif
            <p class="p66-note">{{ $reportDate($report['filters']['from']) }} to {{ $reportDate($report['filters']['to']) }}</p>
            @if($attendance['pairs'] ?? [])
                <p>There are two consecutive weeks of recorded non-attendance. Contact GTA to agree the next step.</p>
            @endif
        </article>

        <article class="p66-progress-card">
            <h3>English and maths</h3>
            <dl>
                @foreach(['english' => 'English', 'maths' => 'Maths'] as $subject => $name)
                    @php($topic = $p['topics'][$subject] ?? null)
                    <dt>{{ $name }}</dt>
                    <dd>{{ $topic['qualification'] ?? 'Qualification status not recorded' }}</dd>
                    @if(!empty($topic['assigned']))
                        <dd>{{ $topic['completed'] }} of {{ $topic['assigned'] }} assigned topics complete</dd>
                    @endif
                @endforeach
            </dl>
        </article>
    </div>
</section>

<section class="p66-personal-summary" aria-labelledby="p66-coming-up">
    <h2 id="p66-coming-up">Coming up</h2>
    <div class="p66-personal-grid">
        <article class="p66-progress-card">
            <h3>My next review</h3>
            <p>{{ ($p['appointment'] ?? 'Not recorded') === 'Not recorded' ? 'Date to be confirmed' : $p['appointment'] }}</p>
            @if($p['review_due'])
                <p class="p66-note">Review due {{ $reportDate($p['review_due']) }}</p>
            @endif
            <a href="{{ $p['review_url'] }}">Review information</a>
        </article>
        <article class="p66-progress-card">
            <h3>My journey</h3>
            <p>{{ $p['roadmap']['position'] ?? 'Current position not recorded' }}</p>
            @if(!$report['demo'])
                <a href="{{ route('journey.show', ['apprentice' => $p['id']]) }}">Open my journey</a>
            @endif
        </article>
    </div>
    @if($report['demo'] && !empty($p['roadmap']))
        <details class="p66-more"><summary>Open fictional roadmap</summary>@include('progress67.roadmap')</details>
    @elseif(!empty($p['roadmap']))
        <details class="p66-more"><summary>Roadmap events and download</summary>@include('progress67.roadmap')</details>
    @endif
</section>

@if(!$report['demo'])
    <nav class="p66-help" aria-label="Training and help">
        <a href="{{ route('learning.index', ['apprentice' => $p['id']]) }}">My tasks and feedback</a>
        <a href="{{ route('experience.index') }}#new-help">I need help</a>
    </nav>
    @if($p['feedback'])
        <details class="p66-more">
            <summary>Recent tutor feedback</summary>
            @foreach($p['feedback'] as $feedback)
                <blockquote>
                    <p>{{ $feedback['text'] }}</p>
                    @if($feedback['date'])<footer>{{ $reportDate($feedback['date']) }}</footer>@endif
                </blockquote>
            @endforeach
        </details>
    @endif
    <details class="p66-more">
        <summary>Support and sharing</summary>
        <div class="toolbar">
            <a href="{{ route('workplace-support.index') }}">Support sharing choices</a>
            <a href="{{ route('help.index', ['guide' => 'help-request']) }}">Support and safeguarding guidance</a>
        </div>
    </details>
@endif

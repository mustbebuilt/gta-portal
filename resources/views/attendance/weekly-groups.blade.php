<section class="panel" aria-labelledby="weekly-groups">
    <h2 id="weekly-groups">Weekly group registers</h2>
    @forelse($groups as $group)
        @continue(request()->filled('group') && (int) request('group') !== $group->id)
        @php
            $next = \App\Support\WeeklyRegisters::thisWeek($group) ?? \App\Support\WeeklyRegisters::next($group);
            $regular = $weeklyGroupSessions->get($group->id, collect());
            $existing = $next ? $regular->firstWhere('regular_week', $next['week']) : null;
            $status = $existing ? match(\App\Support\RegisterFollowup::readiness($existing)['bucket']) {
                'recorded' => 'Submitted', 'needs-register' => 'Overdue', 'cancelled' => 'Cancelled',
                'checks' => 'Needs checking', default => $existing->session_date->isToday() ? 'Due' : 'Upcoming',
            } : ($next ? ($next['date'] === today()->toDateString() ? (now()->gte(\Carbon\Carbon::parse($next['date'].' '.$next['settings']['end'])) ? 'Overdue' : 'Due') : 'Upcoming') : null);
        @endphp
        <article class="work-row">
            <div>
                <h3>{{ $group->name }}</h3>
                @if($next)
                    <p>{{ $existing?->session_date->format('D j M Y') ?? \Carbon\Carbon::parse($next['date'])->format('D j M Y') }} · {{ $existing?->start_time ?? $next['settings']['start'] }}–{{ $existing?->end_time ?? $next['settings']['end'] }} · {{ $status }}</p>
                @elseif(isset($group->weekly_register['closed_on']))
                    <p>Weekly schedule closed · Last active day {{ $group->weekly_register['closed_on'] }}</p>
                @else
                    <p>{{ $group->active ? 'Weekly schedule not set' : 'Inactive group' }}</p>
                @endif
            </div>
            <div>
                @if($existing)
                    <a class="button primary" href="{{ route('attendance.show', $existing) }}">Open register</a>
                @elseif($next)
                    <form method="post" action="{{ route('attendance.weekly.open', $group) }}">@csrf
                        <input type="hidden" name="date" value="{{ $next['date'] }}"><input type="hidden" name="week" value="{{ $next['week'] }}">
                        <button class="primary">Open register</button>
                    </form>
                @endif
                <a href="{{ route('attendance.index', ['group'=>$group->id,'view'=>'needs-register']) }}">Overdue registers</a>
                <a href="{{ route('attendance.index', ['group'=>$group->id]).'#session-planner' }}">Add training session</a>
            </div>
        </article>
        @if($group->active && \App\Support\LearningAccess::manager(auth()->user()))
            <details>
                <summary>{{ $group->weekly_register ? 'Change weekly schedule' : 'Set up weekly register' }}</summary>
                <form method="post" action="{{ route('attendance.weekly.configure', $group) }}">@csrf
                    <input type="hidden" name="record_version" value="{{ $group->record_version }}">
                    <div class="form-grid">
                        <label>First session date <input type="date" name="first_day" min="{{ today()->toDateString() }}" required></label>
                        <label>Start time <input type="time" name="start" value="{{ $next['settings']['start'] ?? '' }}" required></label>
                        <label>Finish time <input type="time" name="end" value="{{ $next['settings']['end'] ?? '' }}" required></label>
                        <label>Excluded period start <input type="time" name="break_start" value="{{ $next['settings']['breaks'][0]['start'] ?? '' }}"></label>
                        <label>Excluded period finish <input type="time" name="break_end" value="{{ $next['settings']['breaks'][0]['end'] ?? '' }}"></label>
                        <label>Planned training <textarea name="planned_learning" required>{{ $next['settings']['planned_learning'] ?? '' }}</textarea></label>
                    </div>
                    <label class="check"><input type="checkbox" name="breaks_checked" value="1" required> Check the excluded period or confirm there is none</label>
                    <button>Save weekly schedule</button>
                </form>
            </details>
            @if($next)
                <details>
                    <summary>Close weekly schedule</summary>
                    <form method="post" action="{{ route('attendance.weekly.close', $group) }}">@csrf
                        <input type="hidden" name="record_version" value="{{ $group->record_version }}">
                        <label>Last active day <input type="date" name="closed_on" min="{{ today()->toDateString() }}" required></label>
                        <label>Reason <textarea name="reason" required></textarea></label>
                        <button>Close schedule</button>
                    </form>
                </details>
            @endif
        @endif
    @empty
        <p>No groups are available for this account.</p>
    @endforelse
</section>

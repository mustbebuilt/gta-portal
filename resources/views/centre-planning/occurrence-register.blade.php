@php
    $entry = $action['entry'];
    $defaults = $action['defaults'];
    $editing = (int) old('_register_entry') === $entry->id;
    $breaks = collect($editing ? old('breaks', []) : ($defaults['breaks'] ?? []))
        ->filter(fn ($break) => is_array($break))->take(10)->values()->all() ?: [[]];
@endphp
@if($action['canCreate'] || $action['review'] || $action['canLink'] || $entry->link_proof)
<section class="panel" id="occurrence-register-{{ $entry->id }}">
    <h2>{{ $entry->work_date->format('d M Y') }} · {{ $entry->start_time }}–{{ $entry->end_time }}</h2>
    @if($action['canCreate'])
        <form method="post" action="{{ route('occurrence-register.create', $entry) }}">
            @csrf
            <input type="hidden" name="version" value="{{ $p->version }}">
            <input type="hidden" name="_register_entry" value="{{ $entry->id }}">
            @if($defaults)<p>Learning and breaks are filled from the weekly register schedule for this date.</p>@endif
            <label>Planned learning
                <textarea name="planned_learning" maxlength="10000" required>{{ $editing ? old('planned_learning') : ($defaults['planned_learning'] ?? '') }}</textarea>
            </label>
            @foreach($breaks as $i => $break)
                <div class="form-grid">
                    <label>Excluded break start <input type="time" name="breaks[{{ $i }}][start]" value="{{ $break['start'] ?? '' }}"></label>
                    <label>Excluded break end <input type="time" name="breaks[{{ $i }}][end]" value="{{ $break['end'] ?? '' }}"></label>
                </div>
            @endforeach
            <label class="check"><input type="checkbox" name="breaks_checked" value="1" required> I checked the planned learning and excluded breaks. Leave both break fields blank for no break.</label>
            <button class="primary" name="confirmed" value="1">Create and link register</button>
        </form>
    @elseif($action['review'])
        <p role="status">Check the planning source and any overlapping registers before opening or creating a register for this session.</p>
        @foreach($action['overlaps'] as $overlap)
            <p><a href="{{ route('attendance.show', $overlap) }}">Review {{ $overlap->name }} · {{ $overlap->start_time }}–{{ $overlap->end_time }}</a></p>
        @endforeach
    @endif

    @if($action['canLink'] || $entry->link_proof)
        <details>
            <summary>Manage planning link</summary>
            @if($action['canLink'])
                <form method="post" action="{{ route('centre-planning.link', $entry) }}">
                    @csrf
                    <input type="hidden" name="version" value="{{ $p->version }}">
                    <input type="hidden" name="session_id" value="{{ $action['session']->id }}">
                    <input type="hidden" name="session_version" value="{{ $action['session']->version }}">
                    <label class="check"><input type="checkbox" name="confirmed" value="1" required> I checked the matching register and roster.</label>
                    <button>Link existing register to timetable</button>
                </form>
            @else
                @if($action['needsLinkReview'])
                    <p>The planning link needs checking.</p>
                    @if($action['linked'] && $action['receipt'])
                        <form method="post" action="{{ route('occurrence-register.reconcile', $entry) }}">
                            @csrf
                            <input type="hidden" name="version" value="{{ $p->version }}">
                            <input type="hidden" name="link_hash" value="{{ \App\Support\ReviewedImports::hash($entry->link_proof) }}">
                            <input type="hidden" name="delivery_version" value="{{ $action['receipt']->version }}">
                            <input type="hidden" name="source_hash" value="{{ \App\Support\TimetableInsights::source($action['linked'])['hash'] }}">
                            <label>Reconciliation reason <textarea name="reason" maxlength="2000" required></textarea></label>
                            <label class="check"><input type="checkbox" name="confirmed" value="1" required> I confirmed actual delivery in Workload &amp; register measures and checked this exact occurrence.</label>
                            <button>Reconcile delivered register link</button>
                        </form>
                    @endif
                @endif
                <form method="post" action="{{ route('centre-planning.unlink', $entry) }}">
                    @csrf
                    <input type="hidden" name="version" value="{{ $p->version }}">
                    <input type="hidden" name="link_hash" value="{{ \App\Support\ReviewedImports::hash($entry->link_proof) }}">
                    <label class="check"><input type="checkbox" name="confirmed" value="1" required> Remove this link from the timetable.</label>
                    <button>Remove planning link</button>
                </form>
            @endif
        </details>
    @endif
</section>
@endif

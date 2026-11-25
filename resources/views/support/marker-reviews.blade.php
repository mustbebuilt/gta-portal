@php
    $frequencies = ['weekly'=>'Weekly','fortnightly'=>'Fortnightly','monthly'=>'Monthly','quarterly'=>'Every three months','six-monthly'=>'Every six months'];
    $openByCode = $concerns->where('status', '!=', 'Closed')->whereNotNull('code')->groupBy('code');
    $markerCodes = collect($markers)->merge($openByCode->keys())->unique()->sort(SORT_NATURAL);
@endphp
<section class="panel" id="marker-reviews">
    <h2>Markers and reasons</h2>
    @forelse($markerCodes as $code)
        <article class="support-item">
            <h3>{{ $code }} · {{ $codes[$code] ?? 'Marker' }}</h3>
            @unless($openByCode->has($code))
                @php
                    $added = $markerHistory->first(fn ($change) => in_array($code, json_decode($change->after_codes, true), true)
                        && !in_array($code, json_decode($change->before_codes, true), true));
                @endphp
                @if(filled($added?->reason)) <p>{{ $added->reason }}</p> @endif
            @endunless
            @foreach($openByCode->get($code, collect()) as $marker)
            @php
                $schedule = $schedules->get($marker->id);
                $due = $schedule ? $schedule->next_due_date : $marker->next_review;
                $first = $schedule ? !$schedule->first_review_completed : ($marker->status === 'New' || !$due);
                $form = $schedule ? route('support-register71.review', $schedule) : route('support.marker-review',$marker);
                $restore = old('_support_form') === $form;
                $reason = $marker->initial_note;
                if (in_array($reason, [\App\Http\Controllers\SupportController::MARKER_WITHOUT_REASON, $code.' recorded on Support and Retention Register.'], true)) {
                    $reason = null;
                }
                $description = $marker->other_code;
                $intervals = $schedule ? \App\Support\SupportRegister71::INTERVALS : $frequencies;
                $frequencyKey = $schedule ? 'interval_key' : 'frequency';
                $dateKey = $schedule ? 'review_date' : 'date';
                $outcomeKey = $schedule ? 'note' : 'outcome';
                $frequency = $schedule?->interval_key ?? (collect($marker->history ?? [])->last(fn ($event) => isset($event['frequency']))['frequency'] ?? 'monthly');
            @endphp
                @if(filled($description)) <p>{{ $description }}</p> @endif
                @if(filled($reason) && $reason !== $description) <p>{{ $reason }}</p> @endif
                <p @class(['register-overdue' => $due?->lt(today())])>
                    @if($due?->lt(today())) Overdue since {{ $due->format('j M Y') }}
                    @elseif($first) First review needed
                    @elseif($due?->isToday()) Due today
                    @else Next review {{ $due?->format('j M Y') }}
                    @endif
                </p>
                <details @if($restore) open @endif><summary>Record review</summary>
                    <form method="post" action="{{ $form }}">
                        @csrf
                        <input type="hidden" name="_support_form" value="{{ $form }}">
                        <input type="hidden" name="version" value="{{ $restore ? old('version') : ($schedule?->version ?? $marker->version) }}">
                        @if($schedule)<input type="hidden" name="kind" value="formal_review">@endif
                        <div class="form-grid">
                            <label>Review date <input type="date" name="{{ $dateKey }}" value="{{ $restore ? old($dateKey) : today()->toDateString() }}" max="{{ today()->toDateString() }}" required></label>

                        </div>
                        <details @if($restore && $errors->has($frequencyKey)) open @endif><summary>Change review frequency</summary><label>Review frequency <select name="{{ $frequencyKey }}" required>
                                @foreach($intervals as $value=>$label)<option value="{{ $value }}" @selected(($restore ? old($frequencyKey) : $frequency) === $value)>{{ $label }}</option>@endforeach
                            </select></label></details>
                        @include('shared.staff-template-picker', ['pickerLabel'=>'Review outcome template','family'=>'review_outcomes','fieldNames'=>[$outcomeKey]])
                        <label>Review outcome <textarea name="{{ $outcomeKey }}" required maxlength="1000">{{ $restore ? old($outcomeKey) : '' }}</textarea></label>
                        <details @if($restore && (old('summary') || $errors->hasAny(['summary', 'measure', 'owner_id', 'due_date']))) open @endif>
                            <summary>Add an agreed SMART target</summary>
                            <input type="hidden" name="request_uuid" value="{{ $restore && old('request_uuid') ? old('request_uuid') : (string) \Illuminate\Support\Str::uuid() }}">
                            @include('shared.staff-template-picker', ['pickerLabel'=>'Support target template','family'=>'support_targets','fieldNames'=>['summary','measure']])
                            <label>SMART target <input name="summary" maxlength="2000" value="{{ $restore ? old('summary') : '' }}"></label>
                            <label>How will we know it’s done? <input name="measure" maxlength="1000" value="{{ $restore ? old('measure') : '' }}"></label>
                            <label>Owner <select name="owner_id"><option value="">Choose owner if adding a target</option>
                                @foreach($owners as $owner)<option value="{{ $owner->id }}" @selected($restore && (int) old('owner_id') === $owner->id)>{{ $owner->name }}</option>@endforeach
                            </select></label>
                            <label>Due date <input type="date" name="due_date" value="{{ $restore ? old('due_date') : '' }}"></label>
                        </details>
                        <button class="primary">Save review</button>
                        @if(!empty($inMeeting) && $nextMeeting && $selected['due']->count() === 1 && $selected['due']->contains('id', $marker->id) && $selected['unlinked']->isEmpty())
                            @foreach(request()->only(['view','search','by','marker']) as $key => $value)
                                <input type="hidden" name="meeting_filters[{{ $key }}]" value="{{ $value }}">
                            @endforeach
                            <input type="hidden" name="next_meeting" value="{{ $nextMeeting['person']->id }}">
                            <button name="after_review" value="next">Save review and open next apprentice</button>
                        @endif
                    </form>
                </details>
                @if($schedule)
                    <details><summary>Update or close marker</summary>
                        <form method="post" action="{{ $form }}">
                            @csrf
                            <input type="hidden" name="version" value="{{ $schedule->version }}">
                            <label>Action <select name="kind"><option value="discussion_only">Log discussion only</option><option value="change_frequency">Change frequency</option><option value="close_marker">Close marker</option></select></label>
                            <label>Review frequency <select name="interval_key">@foreach($intervals as $value=>$label)<option value="{{ $value }}" @selected($frequency === $value)>{{ $label }}</option>@endforeach</select></label>
                            <label>Note or reason <textarea name="note" maxlength="2000"></textarea></label>
                            <button>Save marker update</button>
                        </form>
                    </details>
                @elseif($lead)
                    <p><a href="{{ !empty($inMeeting) ? route('support.index', $apprentice).'#concern-'.$marker->id : '#concern-'.$marker->id }}">Manage concern</a></p>
                @endif
            @endforeach
        </article>
    @empty
        <p>No markers recorded.</p>
    @endforelse
    @if($concerns->where('status','!=','Closed')->whereNull('code')->isNotEmpty())
        <p><a href="{{ !empty($inMeeting) ? route('support.index', $apprentice).'#support-concerns' : '#support-concerns' }}">Review unclassified concerns</a></p>
    @endif
    @if($lead)
        @php $trackForm = route('support.track-marker', $apprentice); @endphp
        @php $restoreMarker = old('_support_form') === $trackForm; @endphp
        <details @if($restoreMarker) open @endif><summary>Add a marker</summary>
            <form method="post" action="{{ $trackForm }}">
                @csrf
                <input type="hidden" name="_support_form" value="{{ $trackForm }}">
                <label>Marker <select name="code" required><option value="">Choose a marker</option>
                    @foreach($codes as $code => $label)<option value="{{ $code }}" @selected($restoreMarker && old('code') === $code)>{{ $code }} · {{ $label }}</option>@endforeach
                </select></label>
                <label>Reason (optional except for IM40 Other) <textarea name="reason" rows="2" maxlength="2000">{{ $restoreMarker ? old('reason') : '' }}</textarea></label>
                <label>Review frequency <select name="frequency" required>
                    @foreach($frequencies as $value=>$label)<option value="{{ $value }}" @selected(($restoreMarker ? old('frequency') : 'monthly') === $value)>{{ $label }}</option>@endforeach
                </select></label>
                <button>Add marker</button>
            </form>
        </details>
    @endif
    @if($concerns->isNotEmpty())
        <details><summary>Marker review history</summary>
            @forelse($concerns->reverse() as $marker)
                @foreach(collect($marker->history ?? [])->where('event','formal_marker_review')->reverse() as $event)
                    <p>{{ $marker->code }} · {{ $event['date'] }} · {{ $event['outcome'] }}</p>
                @endforeach
                @foreach(collect($schedules->get($marker->id)?->history ?? [])->reverse() as $event)
                    <p>{{ $marker->code }} · {{ substr($event['at'], 0, 10) }} · {{ ucfirst(str_replace('_',' ',$event['event'])) }}
                        @if($note = $event['note'] ?? $event['outcome'] ?? $event['reason'] ?? null) · {{ $note }} @endif
                    </p>
                @endforeach
            @empty
                <p>No reviews recorded.</p>
            @endforelse
        </details>
    @endif
</section>

@php $frequencies = ['weekly'=>'Weekly','fortnightly'=>'Fortnightly','monthly'=>'Monthly','quarterly'=>'Every three months','six-monthly'=>'Every six months']; @endphp
<section class="panel" id="marker-reviews">
    <h2>Open markers</h2>
    @forelse($concerns->where('status','!=','Closed')->whereNotNull('code') as $marker)
        @php
            $schedule = $schedules->get($marker->id);
            $due = $schedule ? $schedule->next_due_date : $marker->next_review;
            $first = $schedule ? !$schedule->first_review_completed : ($marker->status === 'New' || !$due);
            $form = $schedule ? route('support-register71.review', $schedule) : route('support.marker-review',$marker);
            $restore = old('_support_form') === $form;
            $intervals = $schedule ? \App\Support\SupportRegister71::INTERVALS : $frequencies;
            $frequencyKey = $schedule ? 'interval_key' : 'frequency';
            $dateKey = $schedule ? 'review_date' : 'date';
            $outcomeKey = $schedule ? 'note' : 'outcome';
            $frequency = $schedule?->interval_key ?? (collect($marker->history ?? [])->last(fn ($event) => isset($event['frequency']))['frequency'] ?? 'monthly');
        @endphp
        <article class="support-item">
            <h3>{{ $marker->code }} · {{ $codes[$marker->code] ?? 'Marker' }}</h3>
            <p @class(['register-overdue' => $due?->lt(today())])>
                @if($due?->lt(today())) Overdue since {{ $due->format('j M Y') }}
                @elseif($first) First review needed
                @elseif($due?->isToday()) Due today
                @else Next review {{ $due?->format('j M Y') }}
                @endif
            </p>
            <details @if($restore || ($inlineMarker83??null)===$marker->id) open @endif><summary>Record review · {{ $marker->code }}</summary>
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
                    <label>Review outcome <textarea name="{{ $outcomeKey }}" required maxlength="1000">{{ $restore ? old($outcomeKey) : '' }}</textarea></label>
                    @if(!$schedule && !filled($marker->next_action))
                        <details @if($restore && (old('action') || old('owner') || old('due'))) open @endif><summary>Add a follow-up action</summary>
                            <label>Action <textarea name="action" maxlength="1000">{{ $restore ? old('action') : '' }}</textarea></label>
                            <div class="form-grid">
                                <label>Owner <select name="owner"><option value="">Choose owner</option>
                                    @foreach($owners as $owner)<option value="{{ $owner->id }}" @selected($restore && (int) old('owner') === $owner->id)>{{ $owner->name }}</option>@endforeach
                                </select></label>
                                <label>Due date <input type="date" name="due" value="{{ $restore ? old('due') : '' }}"></label>
                            </div>
                        </details>
                    @endif
                    <button class="primary">Save review</button>
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
                <p><a href="#concern-{{ $marker->id }}">Manage concern</a></p>
            @endif
        </article>
    @empty
        <p>No open classified markers.</p>
    @endforelse
    @foreach($concerns->where('status','!=','Closed')->whereNull('code') as $concern)
        <p><a href="#concern-{{ $concern->id }}">Review unclassified concern</a> · Raised {{ $concern->created_at->format('j M Y') }}</p>
    @endforeach
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
                <label>Description for IM40 (Other) <input name="other" maxlength="250" value="{{ $restoreMarker ? old('other') : '' }}"></label>
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

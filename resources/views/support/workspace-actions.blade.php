<section class="panel" id="support-actions">
    <h2>Action plan</h2>
    @php
        $actionForm = route('support-register71.add-action');
        $restoreAction = old('_support_form') === $actionForm;
        $targetMarkers = $concerns->where('status', '!=', 'Closed')->whereNotNull('code');
        $selectedMarker = $targetMarkers->firstWhere('id', $restoreAction ? old('support_concern_id') : request()->integer('target_marker'));
    @endphp
    <details @if($restoreAction || $selectedMarker) open @endif>
    <summary>Add a target</summary>
    <form method="post" action="{{ $actionForm }}">
        @csrf
        <input type="hidden" name="_support_form" value="{{ $actionForm }}">
        <input type="hidden" name="target_type" value="smart">
        <input type="hidden" name="apprentice_id" value="{{ $apprentice->id }}">
        <input type="hidden" name="request_uuid" value="{{ $restoreAction ? old('request_uuid') : \Illuminate\Support\Str::uuid() }}">
        @if($targetMarkers->isNotEmpty())
            <label>Marker <select name="support_concern_id">
                <option value="">No marker for this target</option>
                @foreach($targetMarkers as $marker)
                    <option value="{{ $marker->id }}" @selected($selectedMarker?->id === $marker->id)>{{ $marker->code }} · {{ $marker->other_code ?: ($codes[$marker->code] ?? 'Marker') }}@if($targetMarkers->where('code', $marker->code)->count() > 1) · {{ $marker->created_at->format('j M Y') }} (#{{ $marker->id }})@endif</option>
                @endforeach
            </select></label>
        @endif
        @include('shared.staff-template-picker', ['pickerLabel'=>'Choose a target or select Custom','family'=>'support_targets','fieldNames'=>['summary','measure'],'choiceName'=>'target_template','savedChoice'=>$restoreAction ? old('target_template') : null])
        <label>What needs to happen? <input name="summary" required maxlength="2000" value="{{ $restoreAction ? old('summary') : '' }}"></label>
        <label>How will we know it’s done? <input name="measure" required maxlength="1000" value="{{ $restoreAction ? old('measure') : '' }}"></label>
        <div class="form-grid">
            <label>Owner <select name="owner_id" required>
                <option value="">Choose owner</option>
                @foreach($owners as $owner)<option value="{{ $owner->id }}" @selected((int) ($restoreAction ? old('owner_id') : auth()->id()) === $owner->id)>{{ $owner->name }}</option>@endforeach
            </select></label>
            <label>Due date <input type="date" name="due_date" required value="{{ $restoreAction ? old('due_date') : '' }}"></label>
        </div>
        <button class="primary">Save target</button>
    </form>
    </details>
    @php($openActions = collect())
    @foreach($concerns->where('status','!=','Closed')->filter(fn ($marker) => filled($marker->next_action)) as $marker)
        @php($openActions->push(['title'=>$marker->next_action, 'owner'=>$marker->owner?->name, 'due'=>$marker->action_due?->toDateString(), 'route'=>route('support.complete-action',$marker), 'version'=>$marker->version]))
    @endforeach
    @foreach($sharedActions->where('state','Open') as $action)
        @php($openActions->push(['title'=>$action->details['summary'] ?? 'Follow-up action', 'marker'=>$concerns->firstWhere('id', $action->support_concern_id)?->code, 'measure'=>$action->details['measure'] ?? null, 'owner'=>$action->owner?->name, 'due'=>$action->due_date->toDateString(), 'route'=>route('support-register71.complete-action',$action)]))
    @endforeach
    @foreach($plans->where('status','Active') as $plan)
        @foreach($plan->activities ?? [] as $activity)
            @if(!empty($activity['followup']) && empty($activity['followup']['completed_at']))
                @php($followup = $activity['followup'])
                @php($openActions->push(['title'=>$followup['note'], 'owner'=>$followup['owner_name'], 'due'=>$followup['due'], 'route'=>route('support.activity.followup',[$plan,$activity['id']]), 'version'=>$plan->version]))
            @endif
        @endforeach
    @endforeach
    @forelse($openActions->sortBy(fn ($action) => $action['due'] ?? '9999-12-31') as $action)
        <div class="support-item">
            <p><strong>{{ $action['title'] }}</strong></p>
            @if(!empty($action['marker'])) <p>Marker {{ $action['marker'] }}</p> @endif
            @if(filled($action['measure'] ?? null)) <p>{{ $action['measure'] }}</p> @endif
            <p class="muted">{{ $action['owner'] ?? 'Owner to assign' }} · {{ $action['due'] ? 'Due '.$action['due'] : 'Due date to set' }}</p>
            <details @if(old('_support_form') === $action['route']) open @endif><summary>Complete action</summary>
                <form method="post" action="{{ $action['route'] }}">
                    @csrf
                    <input type="hidden" name="_support_form" value="{{ $action['route'] }}">
                    @isset($action['version'])<input type="hidden" name="version" value="{{ old('_support_form') === $action['route'] ? old('version') : $action['version'] }}">@endisset
                    <label>Outcome <textarea name="outcome" required maxlength="1000">{{ old('_support_form') === $action['route'] ? old('outcome') : '' }}</textarea></label>
                    <button class="primary">Save outcome</button>
                </form>
            </details>
        </div>
    @empty
        <p>No open actions.</p>
    @endforelse
    <details><summary>Completed action history</summary>
        @foreach($concerns as $marker)
            @foreach(collect($marker->history ?? [])->where('event','marker_action_completed') as $event)
                <p><strong>{{ $event['before']['next_action'] ?? 'Marker action' }}</strong> · {{ $event['outcome'] }}</p>
            @endforeach
        @endforeach
        @foreach($sharedActions->where('state','Completed') as $action)
            <p><strong>{{ $action->details['summary'] ?? 'Follow-up action' }}</strong> · {{ $action->owner?->name ?? 'Former staff member' }} · {{ $action->completed_at?->format('j M Y') }} · {{ $action->details['outcome'] ?? 'Completed' }}</p>
        @endforeach
        @foreach($plans as $plan)
            @foreach($plan->activities ?? [] as $activity)
                @if(!empty($activity['followup']['completed_at']))
                    <p><strong>{{ $activity['followup']['note'] }}</strong> · {{ $activity['followup']['outcome'] }}</p>
                @endif
            @endforeach
        @endforeach
    </details>
</section>

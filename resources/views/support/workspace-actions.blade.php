<section class="panel" id="support-actions">
    <h2>Open actions</h2>
    @php($openActions = collect())
    @foreach($concerns->where('status','!=','Closed')->filter(fn ($marker) => filled($marker->next_action)) as $marker)
        @php($openActions->push(['title'=>$marker->next_action, 'owner'=>$marker->owner?->name, 'due'=>$marker->action_due?->toDateString(), 'route'=>route('support.complete-action',$marker), 'version'=>$marker->version]))
    @endforeach
    @foreach($sharedActions->where('state','Open') as $action)
        @php($openActions->push(['title'=>$action->details['summary'] ?? 'Follow-up action', 'owner'=>$action->owner?->name, 'due'=>$action->due_date->toDateString(), 'route'=>route('support-register71.complete-action',$action)]))
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
    @php($actionForm = route('support-register71.add-action'))
    <details @if(old('_support_form') === $actionForm) open @endif><summary>Add action</summary>
        <form method="post" action="{{ $actionForm }}">
            @csrf
            <input type="hidden" name="_support_form" value="{{ $actionForm }}">
            <input type="hidden" name="apprentice_id" value="{{ $apprentice->id }}">
            <input type="hidden" name="request_uuid" value="{{ old('_support_form') === $actionForm ? old('request_uuid') : \Illuminate\Support\Str::uuid() }}">
            <label>Next action <input name="summary" required maxlength="2000" value="{{ old('_support_form') === $actionForm ? old('summary') : '' }}"></label>
            <div class="form-grid">
                <label>Owner <select name="owner_id" required>
                    @foreach($owners as $owner)<option value="{{ $owner->id }}" @selected((int) (old('_support_form') === $actionForm ? old('owner_id') : auth()->id()) === $owner->id)>{{ $owner->name }}</option>@endforeach
                </select></label>
                <label>Due date <input type="date" name="due_date" required value="{{ old('_support_form') === $actionForm ? old('due_date') : today()->toDateString() }}"></label>
            </div>
            <button class="primary">Save action</button>
        </form>
    </details>
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

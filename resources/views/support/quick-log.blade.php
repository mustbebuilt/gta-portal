<details class="panel" id="quick-support-log" @if(old('return_to_workspace') || $plans->contains(fn ($plan) => old('_support_form') === route('support.activity', $plan))) open @endif>
    <summary>Log support delivered</summary>
    @php($activePlans = $plans->where('status','Active')->filter(fn ($plan) => filled($plan->active_plan['approved_at'] ?? null)))
    @forelse($activePlans as $plan)
        @php($logForm = route('support.activity',$plan))
        @php($restoreLog = old('_support_form') === $logForm)
        <details @if($activePlans->count() === 1 || $restoreLog) open @endif>
            <summary>{{ $activePlans->count() === 1 ? 'Record a support session' : 'Record a session for plan '.$plan->id }}</summary>
            <form method="post" action="{{ $logForm }}">
                @csrf
                <input type="hidden" name="_support_form" value="{{ $logForm }}">
                <input type="hidden" name="version" value="{{ $restoreLog ? old('version') : $plan->version }}">
                <input type="hidden" name="approved_at" value="{{ $restoreLog ? old('approved_at') : $plan->active_plan['approved_at'] }}">
                <input type="hidden" name="request_id" value="{{ $restoreLog ? old('request_id') : \Illuminate\Support\Str::uuid() }}">
                <input type="hidden" name="who" value="{{ auth()->id() }}">
                <div class="form-grid">
                    <label>Date <input name="date" type="date" value="{{ $restoreLog ? old('date') : today()->toDateString() }}" max="{{ today()->toDateString() }}" required></label>
                    <label>Support type <select name="type" required><option value="">Choose a type</option>
                        @foreach(\App\Http\Controllers\SupportController::ACTIVITY_TYPES as $type)<option @selected($restoreLog && old('type') === $type)>{{ $type }}</option>@endforeach
                    </select></label>
                </div>
                <label>Support delivered <textarea name="delivered" required maxlength="10000">{{ $restoreLog ? old('delivered') : '' }}</textarea></label>
                <label>Session outcome <textarea name="response" maxlength="10000">{{ $restoreLog ? old('response') : '' }}</textarea></label>
                <details @if($restoreLog && (old('objective_id') || old('followup_note'))) open @endif><summary>Objective and follow-up</summary>
                    @include('support.activity-objective-fields')
                </details>
                <button class="primary">Save support activity</button>
            </form>
        </details>
    @empty
        @if(!$currentPlan || \App\Support\RoutineSupport::is($currentPlan))
            <details @if(old('return_to_workspace')) open @endif>
                <summary>{{ $currentPlan ? 'Continue routine support draft' : 'Set up routine support' }}</summary>
                @include('support.routine-form', ['plan'=>$currentPlan, 'inWorkspace'=>true])
            </details>
        @else
            <p>Check the current support plan before recording delivered support.</p>
            <a class="button" href="{{ route('support.plan', [$apprentice, $currentPlan]) }}">Open support plan</a>
        @endif
    @endforelse
    @if($plans->contains(fn ($plan) => !empty($plan->activities)))
        <details><summary>Support session history</summary>
            @foreach($plans as $plan)
                @foreach(array_reverse($plan->activities ?? []) as $activity)
                    <article class="support-item"><h3>{{ $activity['date'] }} · {{ $activity['type'] }}</h3>
                        <p>{{ $activity['delivered'] ?? '' }}</p>
                        @if(!empty($activity['response']))<p>{{ $activity['response'] }}</p>@endif
                    </article>
                @endforeach
            @endforeach
        </details>
    @endif
</details>

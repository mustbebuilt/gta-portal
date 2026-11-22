<section class="panel" id="quick-support-log">
<h2>Log support</h2>
@php($activePlans = $plans->where('status','Active')->filter(fn ($plan) => filled($plan->active_plan['approved_at'] ?? null)))
@forelse($activePlans as $plan)
<details><summary>Log support against plan {{ $plan->id }}</summary>
<form method="post" action="{{ route('support.activity',$plan) }}">
@csrf
<input type="hidden" name="version" value="{{ $plan->version }}">
<input type="hidden" name="approved_at" value="{{ $plan->active_plan['approved_at'] }}">
<input type="hidden" name="request_id" value="{{ \Illuminate\Support\Str::uuid() }}">
<input type="hidden" name="who" value="{{ auth()->id() }}">
<label>Date <input name="date" type="date" value="{{ today()->toDateString() }}" required></label>
<label>Support type <select name="type" required><option value="">Choose a type</option>
@foreach(\App\Http\Controllers\SupportController::ACTIVITY_TYPES as $type)<option>{{ $type }}</option>@endforeach
</select></label>
<label>Support delivered <textarea name="delivered" required></textarea></label>
<label>Session outcome <textarea name="response"></textarea></label>
@include('support.activity-objective-fields')
<button>Save support activity</button>
</form></details>
@empty<p>Open the <a href="#support-plan-list">support plans</a> to set up or check the apprentice’s active plan.</p>@endforelse
</section>

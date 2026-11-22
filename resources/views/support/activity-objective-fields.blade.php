<label>Support objective
    <select name="objective_id">
        <option value="">General support</option>
        @foreach($plan->activeObjectives() as $id => $objective)
        <option value="{{ $id }}" @selected(old('objective_id') === $id)>{{ $objective['title'] }}</option>
        @endforeach
    </select>
</label>
<p><a href="{{ route('support.plan', [$plan->apprentice_id, $plan]) }}#support-objectives">Manage support objectives</a></p>
<details @if(old('followup_note') || $errors->has('followup_owner') || $errors->has('followup_due')) open @endif>
    <summary>Add a follow-up</summary>
    <label>Agreed next action <textarea name="followup_note" maxlength="1000">{{ old('followup_note') }}</textarea></label>
    <label>Due date <input type="date" name="followup_due" value="{{ old('followup_due') }}"></label>
    <label>Owner <select name="followup_owner">
        <option value="">Choose an owner</option>
        @foreach(\App\Support\SupportAccess::owners($plan->apprentice) as $owner)
        <option value="{{ $owner->id }}" @selected((string) old('followup_owner') === (string) $owner->id)>{{ $owner->name }}</option>
        @endforeach
    </select></label>
</details>

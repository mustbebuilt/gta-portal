@if(isset($activity['objective']))
    <p>Support objective: {{ $activity['objective']['title'] }}</p>
@endif
@if($followup = $activity['followup'] ?? null)
    <p>Follow-up due {{ $followup['due'] }} · {{ $followup['owner_name'] }}</p>
    <p class="recorded-answer">{{ $followup['note'] }}</p>
    @if(isset($followup['completed_at']))
        <p>Completed {{ substr($followup['completed_at'], 0, 10) }} · {{ $followup['outcome'] }}</p>
    @elseif($plan->status === 'Active')
        <form method="post" action="{{ route('support.activity.followup', [$plan, $activity['id']]) }}">
            @csrf
            <input name="version" type="hidden" value="{{ $plan->version }}">
            <label>Follow-up outcome <input name="outcome" maxlength="1000" required></label>
            <button>Complete follow-up</button>
        </form>
    @endif
@endif

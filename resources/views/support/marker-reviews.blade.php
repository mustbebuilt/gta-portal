@php($frequencies = ['weekly'=>'Weekly','fortnightly'=>'Fortnightly','monthly'=>'Monthly','quarterly'=>'Every three months','six-monthly'=>'Every six months'])
<section class="panel" id="marker-reviews">
<h2>Marker reviews and actions</h2>
@if($lead)
<details><summary>Add a marker for review</summary>
<form method="post" action="{{ route('support.track-marker', $apprentice) }}">
@csrf
<label>Marker <select name="code" required><option value="">Choose a marker</option>
@foreach($codes as $code => $label)<option value="{{ $code }}">{{ $code }} · {{ $label }}</option>@endforeach
</select></label>
<label>Other description (IM40 only) <input name="other" maxlength="250"></label>
<label>Review frequency <select name="frequency" required><option value="">Choose frequency</option>
@foreach($frequencies as $value=>$label)<option value="{{ $value }}">{{ $label }}</option>@endforeach
</select></label>
<button>Add marker</button></form></details>
@endif
@forelse($concerns->where('status','!=','Closed')->whereNotNull('code') as $marker)
@php($reviews = collect($marker->history ?? [])->where('event','formal_marker_review'))
<details><summary>{{ $marker->code }} · {{ $codes[$marker->code] ?? 'Marker' }}</summary>
<p>Next review · {{ $marker->next_review?->toDateString() ?? 'First review needed' }}</p>
<form method="post" action="{{ route('support.marker-review',$marker) }}">
@csrf
<input type="hidden" name="version" value="{{ $marker->version }}">
<label>Review date <input type="date" name="date" value="{{ today()->toDateString() }}" required></label>
<label>Review frequency <select name="frequency" required>
<option value="">Choose frequency</option>
@foreach($frequencies as $value=>$label)
<option value="{{ $value }}" @selected((collect($marker->history ?? [])->last(fn ($event) => isset($event['frequency']))['frequency'] ?? null) === $value)>{{ $label }}</option>
@endforeach
</select></label>
<label>Review outcome <textarea name="outcome" required maxlength="1000"></textarea></label>
@if(!filled($marker->next_action))
<details><summary>Add a follow-up action</summary>
<label>Action <textarea name="action" maxlength="1000"></textarea></label>
<label>Owner <select name="owner"><option value="">Choose owner</option>
@foreach($owners as $owner)<option value="{{ $owner->id }}">{{ $owner->name }}</option>@endforeach
</select></label>
<label>Due date <input type="date" name="due"></label>
</details>
@endif
<button>Save formal review</button>
</form>
@if(filled($marker->next_action))
<form method="post" action="{{ route('support.complete-action',$marker) }}">
@csrf<input type="hidden" name="version" value="{{ $marker->version }}">
<p>{{ $marker->next_action }} · {{ $marker->owner?->name ?? 'No owner' }} · {{ $marker->action_due?->toDateString() ?? 'No due date' }}</p>
<label>Action outcome <textarea name="outcome" required maxlength="1000"></textarea></label>
<button>Complete action</button>
</form>
@endif
@foreach($reviews->reverse() as $review)<p>{{ $review['date'] }} · {{ $review['outcome'] }}</p>@endforeach
</details>
@empty<p>No open classified markers to review.</p>@endforelse
</section>

@php($key = \App\Support\OperationalFollowups::sourceKey($person, $item))
@if($openFollowups->has($key))
    <a href="{{ route('operations-desk.index', ['followup' => $openFollowups[$key]->id]) }}#follow-up-{{ $openFollowups[$key]->id }}">Open recorded follow-up</a>
@elseif(isset($ownersBySource[$key]) && $ownersBySource[$key]->isNotEmpty())
    <details @if(old('source_key') === $key) open @endif>
        <summary>Start a staff follow-up</summary>
        <form method="post" action="{{ route('operations-desk.start', $person) }}">
            @csrf
            <input type="hidden" name="source_key" value="{{ $key }}">
            <input type="hidden" name="request_token" value="{{ old('source_key') === $key ? old('request_token') : (string) \Illuminate\Support\Str::uuid() }}">
            <label>Owner <select name="owner_id">
                @foreach($ownersBySource[$key] as $owner)
                    <option value="{{ $owner->id }}" @selected((int) (old('source_key') === $key ? old('owner_id') : auth()->id()) === $owner->id)>{{ $owner->name }}</option>
                @endforeach
            </select></label>
            <label>Follow-up due <input type="date" name="due" min="{{ today()->toDateString() }}" value="{{ old('source_key') === $key ? old('due') : '' }}" required></label>
            <label>Action note or reference (exclude private support and health details) <textarea name="reference" maxlength="2000" required>{{ old('source_key') === $key ? old('reference') : '' }}</textarea></label>
            <button name="confirmed" value="1">Start follow-up</button>
        </form>
    </details>
@endif

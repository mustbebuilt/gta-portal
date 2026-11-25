<section class="panel" id="publish-employer-request">
    <h2>Publish a checked employer request</h2>

    @if($grants->isEmpty())
        <p>A verified employer contact is needed. @unless(\App\Support\LearningAccess::manager(auth()->user()))Ask an administrator to verify access for this apprentice.@endunless</p>
    @elseif($sources->isEmpty())
        <p>Choose a review ready for signatures, completed workplace training or an open employer-owned target when one is available.</p>
    @else
        @php
            $sourceValue = old('source_choice', $choice ?? ($sources->count() === 1 ? $sources->keys()->first() : null));
            $source = is_string($sourceValue) ? $sources->get($sourceValue) : null;
            $recipient = old('grant_id', $grants->count() === 1 ? $grants->first()->id : null);
        @endphp
        @if($choice && !$sources->has($choice))
            <p role="status">That record is unavailable for this request. Choose a source below.</p>
        @endif
        <noscript>
            <form method="get" action="{{ route('employer-actions.staff', $apprentice) }}#publish-employer-request">
                <label>Load suggested wording <select name="source">
                    @foreach($sources as $value => $item)
                        <option value="{{ $value }}" @selected($sourceValue === $value)>{{ $item['label'] }}</option>
                    @endforeach
                </select></label>
                <button>Load wording</button>
            </form>
        </noscript>
        <form method="post" action="{{ route('employer-actions.publish', $apprentice) }}" data-employer-request>
            @csrf
            <input type="hidden" name="request_token" value="{{ old('request_token', (string) \Illuminate\Support\Str::uuid()) }}">
            <div class="form-grid">
                <div>
                    <label for="source">Source record</label>
                    <select id="source" name="source_choice" required>
                        <option value="">Choose source</option>
                        @foreach($sources as $value => $item)
                            <option value="{{ $value }}" data-title="{{ $item['title'] }}" data-detail="{{ $item['detail'] }}" data-due="{{ $item['due'] }}" @selected($sourceValue === $value)>{{ $item['label'] }}</option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <label for="grant">Verified recipient</label>
                    <select id="grant" name="grant_id" required>
                        <option value="">Choose named contact</option>
                        @foreach($grants as $grant)
                            <option value="{{ $grant->id }}" @selected((string) $recipient === (string) $grant->id)>{{ $contacts->get($grant->user_id) }}</option>
                        @endforeach
                    </select>
                </div>
            </div>
            <p>The employer will see the title, message and reply date below. Add any details they need to identify the request. Keep private support, careers and HR information out of the message.</p>
            <label for="title">Title</label>
            <input id="title" name="title" maxlength="180" value="{{ old('title', $source['title'] ?? '') }}" required>
            <label for="detail">Message to the employer</label>
            <textarea id="detail" name="detail" rows="3" maxlength="10000" required>{{ old('detail', $source['detail'] ?? '') }}</textarea>
            <label for="due">Reply by (optional)</label>
            <input id="due" name="due_date" type="date" value="{{ old('due_date', $source['due'] ?? '') }}">
            <label class="check"><input type="checkbox" name="confirmed" value="1" required> I checked the recipient and that the message can be shared with them.</label>
            <button class="primary">Publish request</button>
        </form>
        <script src="{{ asset('assets/employer-request.js') }}?v=88" defer></script>
    @endif
</section>

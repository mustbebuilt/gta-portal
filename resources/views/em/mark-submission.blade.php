<section class="panel">
    <h2>Submitted evidence</h2>
    @if($submission)
        <p>Version {{ $submission['version'] ?? 'Not recorded' }}</p>
        @if(filled($submission['comment'] ?? null))
            <p class="recorded-answer">{{ $submission['comment'] }}</p>
        @endif
        <ul>
            @forelse($submission['files'] ?? $topic->files ?? [] as $file)
                <li><a href="{{ route('topics.download', [$topic, $file['id']]) }}">{{ $file['name'] }}</a></li>
            @empty
                <li>No files submitted.</li>
            @endforelse
        </ul>
    @else
        <p>The submitted version needs checking. <a href="{{ route('topics.show', $topic) }}">Open the topic record</a>.</p>
    @endif
</section>
@if($submission)
    <section class="panel">
        <h2>Evidence decision</h2>
        <form method="post" action="{{ route('topics.update', $topic) }}">
            @csrf
            <input type="hidden" name="version" value="{{ $topic->version }}">
            <input type="hidden" name="evidence_only" value="1">
            @foreach($queue as $key => $value)
                <input type="hidden" name="queue[{{ $key }}]" value="{{ $value }}">
            @endforeach
            <label for="topic-decision">Decision</label>
            <select id="topic-decision" name="decision" required>
                <option value="">Choose</option>
                <option value="Accepted" @selected(old('decision') === 'Accepted')>Accept evidence</option>
                <option value="Referred" @selected(old('decision') === 'Referred')>Return for changes</option>
            </select>
            <label for="topic-feedback">Feedback</label>
            <textarea id="topic-feedback" name="feedback" required>{{ old('feedback') }}</textarea>
            <div class="form-actions">
                <button class="primary" name="after_save" value="next">Save decision and open next</button>
                <button>Save and stay</button>
            </div>
        </form>
        <p><a href="{{ route('topics.show', $topic) }}">Open the topic record</a> to record training completion separately.</p>
    </section>
@endif

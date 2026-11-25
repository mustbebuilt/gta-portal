<article class="work-row">
    <div>
        <h3>{{ $request->title }}</h3>
        <p>
            @if($request->apprentice){{ $request->apprentice->first_name }} {{ $request->apprentice->last_name }} · @endif
            @if($reply){{ $request->due_date ? 'Due '.$request->due_date->format('j M Y') : 'Due date not set' }}
            @else{{ $request->state }}@endif
        </p>
    </div>
    <a class="button {{ $reply && $loop->first ? 'primary' : '' }}" href="{{ route('employer-actions.show', $request) }}">{{ $reply ? 'Open request and reply' : 'Open request' }}<span class="sr-only">: {{ $request->title }}</span></a>
</article>

<nav class="toolbar" aria-label="Work to mark views">
    <a class="button" href="{{ route('marking.index') }}" @if(request('kind') !== 'skills') aria-current="page" @endif>Vocational tasks</a>
    <a class="button" href="{{ \App\Support\SubjectMarkingQueue::indexUrl() }}" @if(request('kind') === 'skills') aria-current="page" @endif>Maths and English evidence</a>
</nav>

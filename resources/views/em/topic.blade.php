@extends('layout')
@section('title',$topic->topic)
@section('content')
<header class="page-heading"><div><p class="eyebrow">{{ $apprentice->first_name }} {{ $apprentice->last_name }} · {{ ucfirst($topic->subject) }} · {{ $topic->level }}</p><h1>{{ $topic->topic }}</h1><p>{{ $topic->area }} · {{ $topic->planned_hours }} planned training hours</p><span class="status {{ $topic->status==='Completed'?'achieved':'neutral' }}">{{ $topic->status }}</span></div><a class="button" href="{{ $queue !== null ? \App\Support\SubjectMarkingQueue::indexUrl($queue) : route('em.index',$apprentice) }}">{{ $queue !== null ? 'Back to Work to mark' : 'Back to Maths and English' }}</a></header>
@include('learning.errors')
<section class="panel"><h2>Training and evidence</h2><p>Training: <strong>{{ $topic->status }}</strong> · Completed: {{ $topic->completion_date?->format('d M Y')??'Not recorded' }}</p><p>Evidence: <strong>{{ $topic->evidence_stage }}</strong>. Accepting evidence does not automatically complete training.</p>
@if($learner && $topic->editable())<form method="post" enctype="multipart/form-data" action="{{ route('topics.evidence',$topic) }}">@csrf<input type="hidden" name="version" value="{{ $topic->version }}"><label for="topic-draft">Work completed / evidence reference</label><textarea id="topic-draft" name="draft" rows="5">{{ old('draft',$topic->draft) }}</textarea><label for="combined-topic-file">Add a photo or file (optional, up to 15 MB)</label><input id="combined-topic-file" type="file" name="file" accept=".pdf,.jpg,.jpeg,.png,.docx"><label class="check"><input type="checkbox" name="own_work" value="1">I confirm this is my own work.</label><div class="form-actions"><button name="intent" value="draft">Save draft</button><button class="primary" name="intent" value="submit">Submit evidence</button></div></form>@else<p class="recorded-answer">{{ $topic->draft??'No draft recorded' }}</p>@endif</section>
<section class="panel"><h2>Evidence files</h2>@forelse($topic->files??[] as $file)<div class="work-row"><div>{{ $file['name'] }}</div><a class="button" href="{{ route('topics.download',[$topic,$file['id']]) }}">Download evidence</a></div>@empty<p>No files uploaded.</p>@endforelse
@if($canReview && $topic->editable())<form method="post" enctype="multipart/form-data" action="{{ route('topics.upload',$topic) }}">@csrf<input type="hidden" name="version" value="{{ $topic->version }}"><label for="topic-file">PDF, image or DOCX, up to 15 MB</label><input id="topic-file" name="file" type="file" accept=".pdf,.jpg,.jpeg,.png,.docx" required><div class="form-actions"><button>Upload evidence</button></div><p class="muted">Save draft text before uploading a file.</p></form>@endif</section>
@if($canReview)
    <section class="panel">
        <h2>{{ $queue !== null ? 'Review submitted evidence' : 'Subject tutor update' }}</h2>
        <form method="post" action="{{ route('topics.update', $topic) }}">
            @csrf
            <input type="hidden" name="version" value="{{ $topic->version }}">
            @if($topic->evidence_stage === 'Awaiting review')
                <label for="topic-decision">Evidence decision</label>
                <select id="topic-decision" name="decision">
                    <option value="">Leave awaiting review</option>
                    @foreach(['Accepted', 'Referred'] as $decision)
                        <option @selected(old('decision') === $decision)>{{ $decision }}</option>
                    @endforeach
                </select>
            @endif
            <label for="topic-feedback">Feedback / basis for the training update</label>
            <textarea id="topic-feedback" name="feedback" required>{{ old('feedback') }}</textarea>
            <details @if($queue === null || $errors->has('status') || $errors->has('completion_date')) open @endif>
                <summary>Training status and completion date</summary>
                <div class="form-grid">
                    <div>
                        <label for="topic-status">Training status</label>
                        <select id="topic-status" name="status" required>
                            @foreach((float) $topic->planned_hours === 0.0 ? ['Secure'] : ['Not Started', 'In Progress', 'Completed'] as $value)
                                <option @selected(old('status', $topic->status) === $value)>{{ $value }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div>
                        <label for="topic-complete">Actual completion date (when completed)</label>
                        <input id="topic-complete" type="date" name="completion_date" max="{{ today()->toDateString() }}" value="{{ old('completion_date', $topic->completion_date?->format('Y-m-d')) }}">
                    </div>
                </div>
            </details>
            <div class="form-actions">
                @if($queue !== null && $topic->evidence_stage === 'Awaiting review')
                    @foreach($queue as $key => $value)
                        <input type="hidden" name="queue[{{ $key }}]" value="{{ $value }}">
                    @endforeach
                    <button class="primary" name="after_save" value="next">Save decision and open next</button>
                @endif
                <button @class(['primary' => $queue === null || $topic->evidence_stage !== 'Awaiting review'])>Save tutor update</button>
            </div>
        </form>
    </section>
@endif
<details class="panel"><summary>Topic updates</summary>@forelse(array_reverse($topic->history??[]) as $h)<article class="work-row"><div><strong>{{ $h['after'] }} · {{ $h['at'] }}</strong><p class="recorded-answer">{{ $h['feedback'] }}</p></div></article>@empty<p>No tutor updates recorded.</p>@endforelse</details>
<details class="panel"><summary>Evidence submission history</summary>@forelse(array_reverse($topic->submissions??[]) as $s)<details><summary>Version {{ $s['version'] }} · {{ $s['decision']['outcome']??'Awaiting review' }}</summary><p>{{ $s['submitted_at'] }}</p><p class="recorded-answer">{{ $s['comment'] }}</p>@if($s['decision'])<p>{{ $s['decision']['feedback'] }}</p>@endif</details>@empty<p>No evidence submitted.</p>@endforelse</details>
@endsection
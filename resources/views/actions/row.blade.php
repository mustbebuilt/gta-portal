<article class="panel" id="action-{{ $a->id }}"><header class="page-heading"><div><h3>{{ $a->title }}</h3><p>{{ $a->owner }} · Due {{ $a->deadline()?->format('d M Y')??'Not set' }}</p></div><span class="status {{ $a->currentStatus()==='Completed'?'achieved':($a->overdue()?'red':'neutral') }}">{{ $a->overdue()?'Overdue · ':'' }}{{ $a->currentStatus() }}</span></header><p><strong>Completion check:</strong> {{ $a->measure }}</p>
@if($a->learning_task_id)<div class="form-actions"><a class="button primary" href="{{ route('tasks.show',$a->learning_task_id) }}">Open linked task</a></div><p class="muted">Work, deadline and decision are held in the source task.</p>
@else
@if($a->evidence_note)<p class="recorded-answer"><strong>Completion evidence:</strong> {{ $a->evidence_note }}</p>@endif @if($a->feedback)<p class="recorded-answer"><strong>Tutor feedback:</strong> {{ $a->feedback }}</p>@endif
@if(!$manage && $a->owner==='Apprentice' && $a->status==='To do')<form method="post" action="{{ route('actions.progress',$a) }}">@csrf<input type="hidden" name="version" value="{{ $a->version }}"><input type="hidden" name="intent" value="submit"><label for="action-proof-{{ $a->id }}">What have you completed? Give the evidence reference.</label><textarea id="action-proof-{{ $a->id }}" name="evidence_note" required></textarea><div class="form-actions"><button class="primary">Send to tutor</button></div></form>@endif
@if($manage && $a->status!=='Completed')<details><summary>Check action completion</summary><form method="post" action="{{ route('actions.progress',$a) }}">@csrf<input type="hidden" name="version" value="{{ $a->version }}"><label for="action-feedback-{{ $a->id }}">Feedback / evidence checked</label><textarea id="action-feedback-{{ $a->id }}" name="feedback" required></textarea><label for="action-completed-{{ $a->id }}">Actual completion date (for completion)</label><input id="action-completed-{{ $a->id }}" name="completion_date" type="date" max="{{ now()->toDateString() }}"><div class="form-actions"><button name="intent" value="return">Return to To do</button>@if($a->status==='With tutor'||$a->owner!=='Apprentice')<button class="primary" name="intent" value="complete">Confirm completed</button>@endif</div></form></details>@endif
@endif
@if($manage && $a->currentStatus()==='To do')<div class="form-actions"><a class="button" href="{{ route('actions.edit',[$apprentice,$a]) }}">Edit target</a></div>@endif
@if(\App\Support\EmployerActions::staff(auth()->user(), $apprentice) && $a->owner === 'Employer / workplace mentor' && $a->currentStatus() !== 'Completed')
    <p><a href="{{ route('employer-actions.staff', ['apprentice' => $apprentice, 'source' => 'target:'.$a->id]) }}#publish-employer-request">Request an employer update</a></p>
@endif
<details><summary>Target details and history</summary>
<dl class="details-grid">
    <div><dt>Learning need</dt><dd>{{ $a->learning_reference }}</dd></div>
    <div><dt>Support</dt><dd>{{ $a->support }}</dd></div>
    @if($a->currentStatus() === 'Completed')
        <div><dt>Completion date</dt><dd>{{ $a->completion_date?->format('d M Y') ?? ($a->learning_task_id ? 'See source task decision' : 'Not recorded') }}</dd></div>
    @endif
</dl>
@foreach(array_reverse($a->history??[]) as $history)<article><p><strong>{{ $history['event'] }} · {{ $history['at'] }}</strong></p><p>{{ $history['feedback']??($history['measure']??'') }}</p></article>@endforeach</details></article>

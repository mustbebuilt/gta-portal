@php($availability=\App\Support\TaskAvailability::for($task,$release))
<section class="panel" aria-labelledby="task-next-step"><h2 id="task-next-step">What happens next?</h2>
@if($task->template['kind']==='source-pending')<p>The programme source must be checked before answers, evidence or a decision can be recorded. Ask an administrator to investigate this source.</p>
@elseif($availability['state']==='locked')<h3>Training block not released</h3><p>{{ $availability['detail'] }}</p><p>The recorded deadline is not changed by this message. Saved work remains available to read; check the plan with the Tutor.</p>
@elseif($task->template['kind']==='knowledge')<p>This is a paper assessment. The authorised Tutor records the test title, marks, outcome and feedback; uploading evidence does not record a test result.</p>
@elseif($task->status==='Approved')<p>This task has a recorded approval. It is separate from a qualification result or completion of the apprenticeship.</p>
@elseif($task->status==='Awaiting marking')<p>This version is with the tutor for marking.</p>@if($tutor)<p><a href="#task-work">Read the submitted answers and evidence</a> before recording your decision.</p>@endif
@elseif($learner&&$task->editable())<p>Save a draft to finish later, or submit your answers and files together when you are ready.</p>
<p><a href="#task-work">{{ $task->status === 'Referred' ? 'Update answers and evidence' : 'Open answers and evidence' }}</a></p>
@if($task->status==='Referred') @php($referral=collect($task->submissions??[])->reverse()->first(fn($s)=>($s['decision']['status']??null)==='Referred'))<h3>Most recent referral feedback</h3>@if(filled($referral['decision']['feedback']??null))<p class="recorded-answer">{{ $referral['decision']['feedback'] }}</p>@else<p>No referral feedback is recorded in the available history. Ask your Tutor which changes are needed.</p>@endif<p>Make the requested corrections, confirm the work is your own and submit it again.</p>@endif
@else<p>Check the current task status and block release message. The assigned apprentice submits their own work; Tutor decisions use the submitted version.</p>
@endif
</section>
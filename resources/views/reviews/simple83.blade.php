@extends('layout')
@section('title','Apprentice Progress Review')
@section('content')
@php
$f=$review->fields??[];
$editable=$review->state!=='Ready for signatures';
$labels=\App\Support\ReviewPreparation::SIMPLE_SECTIONS;
$changes=old('fields.planChange',$f['planChange']??'');
$contribution=old('fields.attendance',$f['attendance']??'');
$targets=$editable ? $actions->map(fn($a)=>['title'=>$a->title,'owner'=>$a->owner,'status'=>$a->currentStatus(),'due_date'=>$a->deadline()?->toDateString(),'completion_date'=>$a->completion_date?->toDateString(),'relevance'=>$a->relevance])->all() : ($snapshot['action_plan']??[]);
@endphp
<header class="page-heading"><div><p class="eyebrow">{{$apprentice->first_name}} {{$apprentice->last_name}} · {{$apprentice->reference}}</p><h1>Apprentice Progress Review</h1><p>{{$review->state??'Draft'}}@if($review->exists) · Version {{$review->version}}@endif · {{$signedConfirmed?'Checked signed evidence retained':'Not a completed review'}}</p></div><a class="button" href="{{route('reviews.index')}}">Back to reviews</a></header>
@include('learning.errors')
@if($preparation && !$preparation['ready'])<details class="panel"><summary>{{count($preparation['pending'])}} saved items to finish</summary><ul>@foreach(collect($preparation['pending'])->groupBy('section') as $section=>$items)<li><a href="#{{$section}}">{{$labels[$section]}}</a>: {{implode('; ',$items->pluck('label')->all())}}</li>@endforeach</ul><p>Typed changes are checked when saved.</p></details>@endif
<nav class="section-nav" aria-label="Review sections">@foreach($labels as $id=>$label)<a href="#{{$id}}">{{$label}}</a>@endforeach</nav>
<form method="post" action="{{$review->exists?route('reviews.update',[$apprentice,$review]):route('reviews.store',$apprentice)}}">@csrf
@if($review->exists)<input type="hidden" name="version" value="{{$review->version}}">@endif
<input type="hidden" name="fields[format83]" value="simple83">
<input type="hidden" name="prepare_plan_hash" value="{{$snapshot['delivery_plan']['hash']??''}}">
<fieldset @disabled(!$editable)>
<section class="panel" id="review-1"><h2>1. Progress</h2>
@if(isset($snapshot['review_summary']))@include('reviews.progress-comparison',['summary'=>$snapshot['review_summary']])@endif
@include('reviews.programme-dates')
@if(($snapshot['review_summary']['attendance']??null)!==null)<p>Review-period attendance: {{$snapshot['review_summary']['attendance']['attended']}} attended · {{$snapshot['review_summary']['attendance']['missed']}} missed · {{$snapshot['review_summary']['attendance']['unmarked']}} unmarked.</p>@endif
@if(!($snapshot['delivery_plan']??null))<p class="warn">A current approved training plan is needed before preparing signatures.</p>@if($editable && \App\Support\LearningAccess::manager(auth()->user()))<p><a href="{{route('delivery.show',$apprentice)}}">Open individual delivery plan</a></p>@endif@elseif(($snapshot['delivery_plan']['hash']??null)!==(\App\Support\DeliveryPlan::approved($apprentice)['hash']??null))<p class="warn">{{$editable?'The linked plan changed. Save and check the refreshed draft before preparing signatures.':'This frozen review retains its original plan. Use the correction workflow if needed.'}}</p>@endif
<details @if(!$review->exists || !filled($f['reviewDate']??null) || $errors->any()) open @endif><summary>Meeting details · {{$f['reviewDate']??'Date not recorded'}}</summary><div class="form-grid">
@foreach(['reviewDate'=>'Actual meeting date','trainingFrom'=>'Training period from'] as $key=>$label)<label>{{$label}}<input type="date" name="fields[{{$key}}]" max="{{today()->toDateString()}}" value="{{old('fields.'.$key,$f[$key]??$automatedDefaults[$key]??'')}}"></label>@endforeach
@foreach(['tutor'=>'Tutor','mentor'=>'Learning & Progress Mentor','employerRep'=>'Employer representative'] as $key=>$label)<label>{{$label}}<input name="fields[{{$key}}]" value="{{old('fields.'.$key,$f[$key]??$automatedDefaults[$key]??'')}}" maxlength="20000"></label>@endforeach
<label>Meeting method<select name="fields[method]"><option value="">Choose</option>@foreach(['Face to face','Video meeting','Telephone','Email'] as $method)<option @selected(old('fields.method',$f['method']??'')===$method)>{{$method}}</option>@endforeach</select></label></div><p>Check actual attendees and dates.</p></details>
<details data-print-expand><summary>Recorded learning and supporting figures</summary>
<p>Figures are captured when saved; topic completion and qualification achievement remain separate.</p>
@include('reviews.formal-ksb-summary')
@forelse($snapshot['delivered']??[] as $row)<p>{{$row['date']}} · {{$row['name']}} · {{$row['subjects']??'Subjects not recorded'}}</p>@empty<p>No attended-session learning recorded in this period.</p>@endforelse
@include('reviews.monthly-development')
<h3>Planned next-period learning</h3><p>Partial bookings only; check the agreed plan.</p>
@foreach($snapshot['planned']??[] as $row)<p>{{$row['date']}} · {{$row['name']}} · {{$row['subjects']}}</p>@endforeach
@foreach($snapshot['delivery_plan']['next_period_blocks']??[] as $block)<p>Block {{$block['number']}} · {{$block['start']}} to {{$block['end']}} · {{$block['hours']}} planned hours</p>@endforeach
<h3>Known task deadlines</h3>@foreach($snapshot['known_next_period_tasks']??[] as $task)<p>{{$task['title']??$task['name']??'Task'}} · {{$task['due_date']??'Not set'}}</p>@endforeach
@foreach($snapshot['topics']??[] as $topic)<p>{{ucfirst($topic['subject'])}} · {{$topic['topic']}} · {{$topic['planned_hours']}} planned hours · {{$topic['status']}}</p>@endforeach
@foreach($snapshot['qualifications']??[] as $q)<p>{{$q['title']}} · {{$q['status']}} · Result {{$q['result']??'Not recorded'}}</p>@endforeach
@if($snapshot['delivery_plan']??null)<p>Plan: {{$snapshot['delivery_plan']['source_ref']}} · {{$snapshot['delivery_plan']['hash']}}</p>@endif
</details></section>
<section class="panel" id="review-2"><h2>2. Discussion</h2>
<label for="simple-summary83">Progress and discussion</label><p>Record learning covered, progress against the plan, apprentice/employer views and any barriers.</p><textarea id="simple-summary83" name="fields[summary]" rows="5" maxlength="20000">{{old('fields.summary',$f['summary']??'')}}</textarea>
<div class="form-grid"><label>Progress judgement<select name="fields[judgement]"><option value="">Choose</option>@foreach(['On track','Behind plan','Unable to confirm'] as $value)<option @selected(old('fields.judgement',$f['judgement']??'')===$value)>{{$value}}</option>@endforeach</select></label>
<label>Employer participation<select name="fields[attendance]" data-review-detail="employer-detail83" data-review-value="Absent — opportunity to contribute recorded"><option value="">Choose</option>@foreach(['Present','Absent — opportunity to contribute recorded'] as $value)<option @selected($contribution===$value)>{{$value}}</option>@endforeach</select></label>
<label>Training-plan change needed?<select name="fields[planChange]" data-review-detail="plan-detail83" data-review-value="Yes"><option value="">Choose</option>@foreach(['No','Yes'] as $value)<option @selected($changes===$value)>{{$value}}</option>@endforeach</select></label>
<label>Support follow-up needed?<select name="fields[newNeeds]"><option value="">Choose</option>@foreach(['No','Yes — follow up in restricted support record'] as $value)<option @selected(old('fields.newNeeds',$f['newNeeds']??'')===$value)>{{$value}}</option>@endforeach</select></label></div>
<details id="employer-detail83" @if($contribution==='Absent — opportunity to contribute recorded') open @endif><summary>Employer contribution when absent</summary><label>How was the employer given an opportunity to contribute?<textarea name="fields[absence]" maxlength="20000">{{old('fields.absence',$f['absence']??'')}}</textarea></label></details>
<details id="plan-detail83" @if($changes==='Yes') open @endif><summary>Training-plan change details</summary><label>What change or action was agreed?<textarea name="fields[planChangeNotes]" maxlength="20000">{{old('fields.planChangeNotes',$f['planChangeNotes']??'')}}</textarea></label></details>
@include('reviews.safety')
<p>Keep private support and incident details in their restricted records.</p>
</section>
<section class="panel" id="review-3"><span id="review-5"></span><h2>3. Targets</h2>
@forelse(collect($targets)->where('status','!=','Completed') as $target)<article class="work-row"><div><h3>{{$target['title']}}</h3>@if(($target['relevance']??null)===\App\Models\SmartAction::OTJ_CATCHUP)<span class="badge">OTJ catch-up</span>@endif<p>{{$target['owner']}} · Due {{$target['due_date']??'Not set'}} · {{$target['status']}}</p></div></article>@empty<p>No unfinished targets recorded.</p>@endforelse
@if($editable && \App\Support\LearningAccess::training(auth()->user(),$apprentice))<a class="button" href="{{route('actions.index',$apprentice)}}">Add or update targets</a>@endif
<details><summary>Completed targets</summary>@foreach(collect($targets)->where('status','Completed') as $target)<p>{{$target['title']}} · {{$target['owner']}} · Completed {{$target['completion_date']??'Date not recorded'}}</p>@endforeach</details>
<details><summary>Action Plan position captured</summary>@foreach($snapshot['action_plan']??[] as $target)<p>{{$target['title']}} · {{$target['status']}} · Due {{$target['due_date']??'Not set'}}@if($target['completion_date']??null) · Completed {{$target['completion_date']}}@endif</p>@endforeach</details>
<p>Targets use the original Action Plan; saving this review does not create copies.</p></section>
<section class="panel" id="review-4"><span id="review-6"></span><h2>4. Finish</h2>
<input type="hidden" name="fields[checked83]" value="0">
<label class="check"><input type="checkbox" name="fields[checked83]" value="1" @checked(old('fields.checked83',$f['checked83']??false))>I checked the progress figures and current plan, agreed realistic targets with the responsible people, and checked the review content.</label>
<p>Next review reference: {{$f['nextReview']??'Calculated after saving the actual meeting date'}} · not a booked appointment.</p>
@if($editable)<div class="form-actions"><button class="primary" name="after_save" value="stay">Save draft</button><button name="after_save" value="prepare">Save and prepare signatures</button></div><p>Preparing signatures freezes this checked version. Participants still need to sign.</p>@endif
</section></fieldset></form>
@if(collect($f)->only(['learning','progressDiscussion','previousNotes','sharedSupport'])->filter(fn($value)=>filled($value))->isNotEmpty())<details class="panel no-print"><summary>Additional recorded notes</summary>@foreach(['learning'=>'Learning notes','progressDiscussion'=>'Progress notes','previousNotes'=>'Previous action notes','sharedSupport'=>'Restricted proposed support summary'] as $key=>$label)@if(filled($f[$key]??null))<h3>{{$label}}</h3><p class="recorded-answer">{{$f[$key]}}</p>@endif @endforeach</details>@endif
@if($editable && !($snapshot['delivery_plan']??null) && \App\Support\LearningAccess::manager(auth()->user()))<details class="panel"><summary>Complete missing apprentice setup</summary><form method="post" action="{{route('setup68.resume-existing',$apprentice)}}">@csrf<button>Continue setup for this apprentice</button></form><p>Uses this existing record. Check the programme, prior learning, employer and agreed plan before returning to the review.</p></details>@endif
@if($review->exists)<details class="panel"><summary>Saved copies and other tools</summary><a class="button" href="{{route('reviews.pdf',[$apprentice,$review])}}">Download review PDF</a><button type="button" data-print>Print review</button>@if($editable)<a class="button" href="{{route('review-briefs.index',[$apprentice,$review])}}">Prepare factual review notes</a>@endif<a class="button" href="{{route('reviews.signed.form',$review)}}">Signed-copy record</a>@foreach($existing as $item)@if($item->id!==$review->id)<p><a href="{{route('reviews.edit',[$apprentice,$item])}}">Review {{$item->id}} · {{$item->fields['reviewDate']??'Date not recorded'}} · {{$item->state}}</a></p>@endif @endforeach</details>@endif
@if(!$editable)<section class="panel"><h2>Participant signatures</h2><a class="button primary" href="{{route('electronic-reviews.prepare-form',$review)}}">Prepare participant sign-off</a>
@if($review->exists && $review->state === 'Ready for signatures' && \App\Support\EmployerActions::staff(auth()->user(), $apprentice))
    <p><a href="{{ route('employer-actions.staff', ['apprentice' => $apprentice, 'source' => 'review:'.$review->id]) }}#publish-employer-request">Request employer feedback</a></p>
@endif
<details><summary>Correct this frozen source</summary><form method="post" action="{{route('reviews.reopen',[$apprentice,$review])}}">@csrf<input type="hidden" name="version" value="{{$review->version}}">@if($hasSignedSource)<p>Withdraw current signed-copy evidence before reopening. The retained evidence and history remain.</p>@endif<label>Reason<textarea name="reason" required></textarea></label><button @disabled($hasSignedSource)>Reopen as draft</button></form></details></section>@endif
<details><summary>How signing works</summary><p>Ready for signatures freezes the checked source. An independent staff check follows participant signing.</p></details>
<script src="{{asset('assets/simple-review83.js')}}" defer></script>
@endsection

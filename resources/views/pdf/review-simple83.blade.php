@extends('pdf.layout')
@section('title','Apprentice Progress Review')
@section('content')
@php($snapshot=$review->source_snapshot)
@php($f=$review->fields)
<h1>Apprentice Progress Review</h1><p><strong>{{$apprentice->first_name}} {{$apprentice->last_name}} · {{$apprentice->reference}}</strong></p>
<p>{{$review->state}} · Review {{$review->id}} · Version {{$review->version}} · {{$f['reviewDate']??'Meeting date not recorded'}}</p>
<p class="notice">{{$review->state==='Ready for signatures'?'UNSIGNED FROZEN SOURCE':'DRAFT'}}. This generated copy does not record participant signatures or completion.</p>
<h2>1. Progress</h2>
<p>Apprenticeship start {{$snapshot['programme_dates']['start']??'Not recorded'}} · Practical finish {{$snapshot['programme_dates']['practical_end']??'Not recorded'}} · Apprenticeship finish {{$snapshot['programme_dates']['apprenticeship_end']??'Not recorded'}}</p>
<p>Training period from {{$f['trainingFrom']??'Not recorded'}} · {{$f['method']??'Method not recorded'}}</p>
<p>Tutor {{$f['tutor']??'Not recorded'}} · Learning & Progress Mentor {{$f['mentor']??'Not recorded'}} · Employer representative {{$f['employerRep']??'Not recorded'}}</p>
@if(isset($snapshot['review_summary']))@include('reviews.progress-comparison',['summary'=>$snapshot['review_summary']])@endif
@if(isset($snapshot['otj_position']['recorded_minutes']))<p>Recorded OTJ {{$snapshot['otj_position']['recorded_minutes']/60}} hours · Agreed target {{$snapshot['otj_position']['target_hours']??'Not linked'}} hours. Loaded records only.</p>@endif
@if($snapshot['review_summary']['attendance']??null)<p>Attendance: {{$snapshot['review_summary']['attendance']['attended']}} attended · {{$snapshot['review_summary']['attendance']['missed']}} missed · {{$snapshot['review_summary']['attendance']['unmarked']}} unmarked.</p>@endif
<p>Task/topic completion and qualification achievement are separate measures.</p>
<h2>2. Discussion</h2><p>Professional judgement: {{$f['judgement']??'Not recorded'}} · Employer participation: {{$f['attendance']??'Not recorded'}}</p><p class="answer">{{$f['summary']??'Discussion not recorded'}}</p>
@if(($f['attendance']??null)==='Absent — opportunity to contribute recorded')<p>Employer contribution: {{$f['absence']??'Not recorded'}}</p>@endif
<p>Training-plan change: {{$f['planChange']??'Not recorded'}}</p>@if(($f['planChange']??null)==='Yes')<p class="answer">{{$f['planChangeNotes']??'Not recorded'}}</p>@endif
<p>Support follow-up needed: {{$f['newNeeds']??'Not recorded'}}. Private support notes stay in the restricted record.</p>
<p>Accidents or near misses: {{$f['safetyAnswer']??'Not recorded'}}. Private follow-up stays in the restricted record.</p>
<h2>3. Targets</h2>
@forelse($snapshot['action_plan']??[] as $target)<div class="record"><p><strong>{{$target['title']}}</strong></p><p>{{$target['owner']}} · {{$target['status']}} · Due {{$target['due_date']??'Not set'}} · Completed {{$target['completion_date']??'Not recorded'}}</p></div>@empty<p>No targets captured in this saved review.</p>@endforelse
<h2>4. Finish</h2><p>Provider check: {{!empty($f['checked83'])?'Recorded':'Not recorded'}} · Next review reference {{$f['nextReview']??'Not recorded'}} (not a booked appointment).</p>
@if($review->state==='Ready for signatures')<div style="page-break-inside:avoid"><h3>Participant signatures</h3>@foreach(['Apprentice'=>$apprentice->first_name.' '.$apprentice->last_name,'Employer representative'=>$f['employerRep']??'Not recorded','Learning & Progress Mentor'=>$f['mentor']??'Not recorded'] as $role=>$name)<p>{{$role}} · {{$name}}</p><p>Signature ____________________ · Date __________</p>@endforeach<p>Frozen source reference: {{\App\Support\SignedReviews::reviewHash($review)}}</p></div>@endif
@if(collect($f)->only(['learning','progressDiscussion','previousNotes'])->filter(fn($value)=>filled($value))->isNotEmpty())<h2>Additional recorded notes</h2>@foreach(['learning'=>'Learning','progressDiscussion'=>'Progress','previousNotes'=>'Previous actions'] as $key=>$label)@if(filled($f[$key]??null))<h3>{{$label}}</h3><p class="answer">{{$f[$key]}}</p>@endif @endforeach @endif
<p class="muted">Captured {{$snapshot['captured_at']??'Not recorded'}}. Private support/incident notes are not included.</p>
@endsection

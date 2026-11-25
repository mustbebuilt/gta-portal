@extends('layout')
@section('title','Apprentice setup — '.\App\Support\GuidedSetup68::STEPS[$step])
@section('content')
<link rel="stylesheet" href="{{asset('assets/setup68.css')}}?v=refined-setup">
<div class="setup68">
<header class="page-heading"><div><p class="eyebrow">Apprentice administration · {{$draft->state}}</p><h1>{{\App\Support\GuidedSetup68::STEPS[$step]}}</h1><p>{{$p['details']['first_name']??'New'}} {{$p['details']['last_name']??'apprentice'}} · Your work is saved as one setup record.</p></div><a href="{{route('setup68.index')}}" class="button">Saved setups</a></header>
<nav aria-label="Setup steps">@foreach(\App\Support\GuidedSetup68::STEPS as $n=>$label)<a href="{{route('setup68.show',[$draft,$n])}}" @if($step===$n)aria-current="step"@endif>{{$n}}. {{$label}}</a>@endforeach</nav>
@if($errors->any())<div role="alert" class="warn"><h2>Check these details</h2><ul>@foreach($errors->all() as $error)<li>{{$error}}</li>@endforeach</ul></div>@endif
@php($shownCalc=$batch_calculation??$review['calculation'])
@if($shownCalc && $step === 4 && $draft->state !== 'Draft')<p>{{isset($batch_calculation)?'Preview of the selected batch — not yet saved.':'Saved training-hours preview. Confirm the setup in Step 7 to apply these hours.'}}</p><div class="metrics">@foreach(['original_hours'=>'Original OTJ','removed_hours'=>'Hours removed','revised_hours'=>'Revised OTJ'] as $key=>$label)<div class="metric">{{$label}}<strong>{{number_format($shownCalc[$key],2)}} <small>hours</small></strong></div>@endforeach<div class="metric">Adjusted KSBs<strong>{{$shownCalc['adjusted_count']}} <small>of {{$shownCalc['ksb_count']}}</small></strong></div></div>@endif
@if($draft->state==='Complete')<section class="panel"><h2>Setup complete</h2><p>The training plan and OTJ dashboard share the same approved target: <strong>{{number_format($p['approved_hours'],2)}} hours</strong>.</p><div class="actions"><a class="button" href="{{route('apprentices.show',$draft->apprentice_id)}}">Open apprentice record</a><a class="button" href="{{route('delivery.show',$draft->apprentice_id)}}">Training plan</a><a class="button" href="{{route('otj.show',$draft->apprentice_id)}}">OTJ dashboard</a></div></section>@endif
@if($draft->state==='Draft'&&$step===5)
    @include('setup68.employer-search')
@endif
@if($draft->state==='Draft'&&$step<7)
<form id="step-form" method="post" action="{{route('setup68.save', [$draft, $step, ...($step === 4 ? $ksbFilters : ($step === 5 && $employerSearch !== '' ? ['employer_search' => $employerSearch] : []))])}}">@csrf<input type="hidden" name="version" value="{{$draft->version}}">
<section class="panel">
@if($step===1)
<p>Existing matches appear after saving.</p><div class="setup-grid">@foreach(['reference'=>'Learner reference *','first_name'=>'First name *','last_name'=>'Last name *','email'=>'Email address'] as $field=>$label)<div><label for="{{$field}}">{{$label}}</label><input id="{{$field}}" name="data[{{$field}}]" value="{{old('data.'.$field,$p['details'][$field]??'')}}" type="{{$field==='email'?'email':'text'}}" maxlength="{{$field==='email'?254:100}}"></div>@endforeach</div>
@if($review['matches'])<div class="warn"><h2>Possible existing records</h2>@foreach($review['matches'] as $match)<p><a href="{{route('apprentices.show',$match['id'])}}">{{$match['reference']}} — {{$match['name']}}</a>. Review before continuing.</p>@endforeach<label for="duplicate_reason">If only the name matches, explain why this is a different person</label><textarea id="duplicate_reason" name="data[duplicate_reason]" maxlength="500">{{$p['details']['duplicate_reason']??''}}</textarea><p>An exact reference or individual-email match must use the existing record.</p></div>@endif
@elseif($step===2)
<label for="marker-state">Inclusion information</label><select id="marker-state" name="data[state]"><option value="">Choose an answer</option>@foreach(['selected'=>'Select applicable markers','none'=>'Confirmed: none apply','pending'=>'Pending: information still being confirmed'] as $value=>$label)<option value="{{$value}}" @selected(($p['markers']['state']??'')===$value)>{{$label}}</option>@endforeach</select><p>Pending stays pending. Marker details are restricted.</p>
<div id="marker-fields"><div class="checklist">@foreach($markers as $code=>$label)<label><input type="checkbox" name="data[codes][]" value="{{$code}}" @checked(in_array($code,$p['markers']['codes']??[]))>{{$code}} — {{$label}}</label>@endforeach</div><div id="marker-other"><label for="other">Other — brief description</label><textarea id="other" name="data[other]" maxlength="500">{{$p['markers']['other']??''}}</textarea></div></div>
@elseif($step===3)
<label for="template">Apprenticeship standard and version *</label><select id="template" name="data[template_id]"><option value="">Choose a controlled programme</option>@foreach($templates as $t)<option value="{{$t->id}}" @selected(($p['programme']['template_id']??null)===$t->id)>{{$t->template['name']??$t->template['standard_code']}} · {{$t->template['standard_code']}} v{{$t->template['standard_version']}} · {{$t->template['full_hours']}} OTJ hours</option>@endforeach</select>@if(!$templates->count())<p class="warn">No approved programme templates are loaded. Contact the curriculum administrator.</p>@endif
<label for="start">First day in learning *</label><input type="date" id="start" name="data[start_date]" value="{{$p['programme']['start_date']??''}}"><p>Check the planned dates when confirming the setup.</p>
<div class="setup-grid"><div><label for="tutor">Main tutor</label><select id="tutor" name="data[tutor_id]"><option value="">Not assigned</option>@foreach($tutors as $staff)<option value="{{$staff->id}}" @selected(($p['programme']['tutor_id']??null)===$staff->id)>{{$staff->name}}</option>@endforeach</select></div><div><label for="group">Tutor group</label><select id="group" name="data[tutor_group_id]"><option value="">Not assigned</option>@foreach($groups as $g)<option value="{{$g->id}}" @selected(($p['programme']['tutor_group_id']??null)===$g->id)>{{$g->name}}</option>@endforeach</select></div>
@foreach(['mentor_id'=>'Learning & Progress Mentor (GTA role)','careers_adviser_id'=>'Careers adviser','maths_tutor_id'=>'Maths tutor','english_tutor_id'=>'English tutor'] as $field=>$label)<div><label for="{{$field}}">{{$label}}</label><select id="{{$field}}" name="data[{{$field}}]"><option value="">Not assigned</option>@foreach($staffOptions[$field] as $id=>$name)<option value="{{$id}}" @selected(($p['programme'][$field]??null)===$id)>{{$name}}</option>@endforeach</select></div>@endforeach</div>
@elseif($step===4)
@include('setup68.rpl-entry')
@elseif($step===6)
@include('setup68.functional-skills')
@elseif($step===5)
@include('setup68.employer-link-step')
@endif
@if($step !== 4 || $review['template'])
<div class="actions"><button class="primary" type="submit" name="next" value="{{$step+1}}">Save and continue →</button><button type="submit" name="next" value="{{$step}}">Save draft</button></div>
@endif
</section></form>
@endif
@if(isset($batch))<div class="warn"><h3>Review these changes before applying</h3><p>Proposed revised OTJ: {{number_format($batch_calculation['revised_hours'],2)}} hours. Existing draft entries have not changed.</p><ul>@foreach($batch['codes'] as $code)<li>{{$code}}: {{$p['rpl']['adjustments'][$code]??0}}% → {{$batch['percent']}}%</li>@endforeach</ul><form method="post" action="{{route('setup68.save', [$draft, 4, ...$ksbFilters])}}">@csrf<input type="hidden" name="version" value="{{$draft->version}}"><input type="hidden" name="binding" value="{{$batch['binding']}}"><input type="hidden" name="percent" value="{{$batch['percent']}}">@foreach($batch['codes'] as $code)<input type="hidden" name="codes[]" value="{{$code}}">@endforeach<button class="primary" name="action" value="batch_apply">Apply these {{count($batch['codes'])}} changes</button></form></div>@endif
@if($step===7)
    @include('setup68.final-check')
@endif
@if(in_array($draft->state,['Ready','Complete']))<details class="panel" id="reopen-setup"><summary>Correct this same setup / RPL record</summary><form method="post" action="{{route('setup68.reopen',$draft)}}">@csrf<input type="hidden" name="version" value="{{$draft->version}}"><label for="reason">Reason for correction *</label><textarea id="reason" name="reason" required maxlength="1000"></textarea><button>Reopen for correction</button></form></details>@endif
@if($draft->state==='Complete')<p><a href="{{route('em.index',$draft->apprentice_id)}}">Continue English/maths assessments, topics and qualifications</a></p>@endif
<details class="panel audit"><summary>Setup change history</summary><ul>@foreach(array_reverse($draft->history??[]) as $event)<li>{{$event['at']}} · {{$event['event']}} · staff {{$event['actor']}}@if(!empty($event['reason'])) — {{$event['reason']}}@endif
@if(isset($event['rpl_before'],$event['rpl_after']))<ul>@foreach(array_unique(array_merge(array_keys($event['rpl_before']['adjustments']??[]),array_keys($event['rpl_after']['adjustments']??[]))) as $code)@if(($event['rpl_before']['adjustments'][$code]??0)!==($event['rpl_after']['adjustments'][$code]??0))<li>{{$code}}: {{$event['rpl_before']['adjustments'][$code]??0}}% → {{$event['rpl_after']['adjustments'][$code]??0}}%</li>@endif @endforeach</ul>@endif</li>@endforeach</ul></details>
</div>
<script src="{{asset('assets/setup68.js')}}?v=refined-setup" defer></script>
@endsection

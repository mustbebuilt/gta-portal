@php
$fs = $fsInput ?? old('data', (!empty($p['fs_applied82']) ? null : ($p['functional_skills82'] ?? null)) ?? (\App\Support\FunctionalSkillsSetup82::current($draft->apprentice_id) ?: \App\Support\FunctionalSkillsSetup82::defaults(auth()->user())));
@endphp
@if(isset($draft))<input type="hidden" name="data[record_version82]" value="{{!empty($p['functional_skills82']) && empty($p['fs_applied82']) ? ($p['fs_outcome_version82']??0) : (\App\Models\ProgrammeOutcome::where('apprentice_id',$draft->apprentice_id)->value('version')??0)}}">@endif
<p>Record assessment results when available. Pending information keeps a named follow-up; qualification achievement is recorded separately.</p>
@foreach(($fsSubjects ?? ['maths'=>'Maths','english'=>'English']) as $subject=>$label)
<fieldset><legend>{{$label}}</legend>
<label for="{{$subject}}-state">Assessment information</label><select id="{{$subject}}-state" name="data[{{$subject}}][state]">@foreach(['pending'=>'Pending','recorded'=>'Assessments recorded'] as $value=>$text)<option value="{{$value}}" @selected(($fs[$subject]['state']??'pending')===$value)>{{$text}}</option>@endforeach</select>
<label for="{{$subject}}-initial">Initial assessment result / reference</label><input id="{{$subject}}-initial" name="data[{{$subject}}][initial_assessment]" maxlength="500" value="{{$fs[$subject]['initial_assessment']??''}}">
<label for="{{$subject}}-diagnostic">Diagnostic result / reference</label><input id="{{$subject}}-diagnostic" name="data[{{$subject}}][diagnostic]" maxlength="500" value="{{$fs[$subject]['diagnostic']??''}}">
<label for="{{$subject}}-owner">Follow-up owner</label><select id="{{$subject}}-owner" name="data[{{$subject}}][owner_id]" required>@foreach(\App\Models\User::where('active',true)->whereIn('role',['admin','ceo','tutor','functional_skills_tutor'])->orderBy('name')->get() as $staff)<option value="{{$staff->id}}" @selected(($fs[$subject]['owner_id']??auth()->id())==$staff->id)>{{$staff->name}}</option>@endforeach</select>
<label for="{{$subject}}-action">Next action</label><input id="{{$subject}}-action" name="data[{{$subject}}][next_action]" maxlength="500" required value="{{$fs[$subject]['next_action']??''}}">
<details><summary>Assign topics and planned hours</summary><p>Leave unassigned topics blank. Zero hours means Secure. Existing topics and evidence are retained.</p>
@foreach(collect(\App\Support\FunctionalSkillsSetup82::catalogue())->filter(fn ($topic) => str_starts_with($topic['subject'], $label.':'))->groupBy('subject', true)->sortKeys() as $group => $levelTopics)
<details @if(array_intersect(array_keys($fs[$subject]['topics'] ?? []), $levelTopics->keys()->all())) open @endif>
<summary>{{ $group }}</summary>
@foreach($levelTopics->groupBy('area', true) as $area => $areaTopics)
<p><strong>{{ $area }}</strong></p>
@foreach($areaTopics->sortBy('topic') as $id => $topic)
@php($existingTopic = collect(\App\Support\FunctionalSkillsSetup82::topicNames($topic))->map(fn ($name) => ($assignedTopics ?? null)?->get(implode('|', [$subject, explode(': ', $group)[1], $name])))->first(fn ($match) => $match !== null))
@if($existingTopic)
    <p>{{ $topic['topic'] }} · {{ $existingTopic->planned_hours }} planned hours already assigned</p>
@else
    <label for="fs-topic-{{$id}}">{{ $topic['topic'] }}</label>
    <input id="fs-topic-{{$id}}" type="number" step="0.01" min="0" max="9999" name="data[{{$subject}}][topics][{{$id}}]" value="{{$fs[$subject]['topics'][$id]??''}}">
@endif
@endforeach
@endforeach
</details>
@endforeach
</details></fieldset>
@endforeach

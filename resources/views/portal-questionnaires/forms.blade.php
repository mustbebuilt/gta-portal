@extends('layout')
@section('title','Questionnaire forms')
@section('content')
<header class="page-heading"><div><h1>Questionnaire forms</h1><p>Use GTA-approved wording and privacy information. A different administrator or CEO publishes the checked revision.</p></div></header>
<a class="button" href="{{ route('portal-questionnaires.index') }}">Questionnaire worklist</a>
@if($errors->any())<div class="errors" role="alert">@foreach($errors->all() as $error)<p>{{ $error }}</p>@endforeach</div>@endif
<section class="panel"><h2>{{ $form->exists?$form->title:'Create a form' }}</h2>@if($form->exists)<p>{{ $form->state }} · Revision {{ $form->revision }} · Record version {{ $form->version }}</p>@endif
@if(!$form->exists||$form->state==='Draft')
<form method="post" action="{{ $form->exists?route('portal-questionnaires.save-form',$form):route('portal-questionnaires.create-form') }}">@csrf @if($form->exists)<input type="hidden" name="version" value="{{ $form->version }}">@endif
<label>Questionnaire type<select name="kind">@foreach(\App\Http\Controllers\SurveyReceiptController::TYPES  as $k=>$title)<option value="{{ $k }}" @selected(old('kind',$form->kind)===$k)>{{ $title }}</option>@endforeach</select></label>
<label>Title<input name="title" maxlength="180" required value="{{ old('title',$form->title) }}"></label>
<label>Introduction<textarea name="introduction" rows="4" maxlength="4000" required>{{ old('introduction',$form->introduction) }}</textarea></label>
<label>Privacy information shown before answering<textarea name="privacy_notice" rows="5" maxlength="6000" required>{{ old('privacy_notice',$form->privacy_notice) }}</textarea></label>
<p>Text questions accept up to 5,000 characters. Choice questions use one option per line. Ongoing wellbeing questions must all be optional. Leave unused question labels blank.</p>
@php($questions=old('questions',$form->questions??[]))
@for($i=0;$i<30;$i++) @php($q=$questions[$i]??['key'=>'q'.($i+1),'label'=>'','type'=>'text','required'=>false,'options'=>[]])
<details @if($i<3||filled($q['label']??null))open @endif><summary>Question {{ $i+1 }}{{ filled($q['label']??null)?' — '.$q['label']:'' }}</summary><fieldset><legend>Question {{ $i+1 }}</legend>
<input type="hidden" name="questions[{{ $i }}][key]" value="{{ $q['key'] }}">
<label>Question text<input name="questions[{{ $i }}][label]" maxlength="500" value="{{ $q['label']??'' }}"></label>
<label>Answer format<select name="questions[{{ $i }}][type]"><option value="text" @selected(($q['type']??'text')==='text')>Text</option><option value="choice" @selected(($q['type']??'text')==='choice')>One choice</option></select></label>
<label><input type="checkbox" name="questions[{{ $i }}][required]" value="1" @checked($q['required']??false)> Answer required before submission</label>
<label>Choice options (one per line; leave blank for text)<textarea name="questions[{{ $i }}][options_text]" rows="3" maxlength="2200">{{ $q['options_text']??implode("\n",$q['options']??[]) }}</textarea></label></fieldset></details>
@endfor<button class="primary">Save draft form</button></form>
@else<p>{{ $form->introduction }}</p><div style="white-space:pre-wrap">{{ $form->privacy_notice }}</div>@foreach($form->questions  as $q)<p>{{ $loop->iteration }}. {{ $q['label'] }} · {{ $q['type'] }} · {{ $q['required']?'Required':'Optional' }} @if($q['options']) · {{ implode(' / ',$q['options']) }} @endif</p>@endforeach
@endif
@if($form->exists)<div class="toolbar">
@if($form->state==='Draft'&&$manager)<form method="post" action="{{ route('portal-questionnaires.form-action',$form) }}">@csrf<input type="hidden" name="version" value="{{ $form->version }}"><input type="hidden" name="action" value="publish"><button>Publish checked form</button></form>@endif
@if($form->state!=='Draft')<form method="post" action="{{ route('portal-questionnaires.form-action',$form) }}">@csrf<input type="hidden" name="version" value="{{ $form->version }}"><input type="hidden" name="action" value="revise"><button>Create new draft revision</button></form>@endif
@if($form->state==='Published'&&$manager)<form method="post" action="{{ route('portal-questionnaires.form-action',$form) }}">@csrf<input type="hidden" name="version" value="{{ $form->version }}"><input type="hidden" name="action" value="retire"><button>Retire from new assignments</button></form>@endif</div>@endif</section>
<section class="panel"><h2>Saved forms</h2><p><a href="{{ route('portal-questionnaires.forms') }}">Create another form</a></p>@foreach($forms  as $f)<p><a href="{{ route('portal-questionnaires.form',$f) }}">{{ $f->title }}</a> · Revision {{ $f->revision }} · {{ $f->state }}</p>@endforeach{{ $forms->links() }}</section>
@endsection

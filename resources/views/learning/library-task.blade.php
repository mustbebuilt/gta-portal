@extends('layout')
@section('title','Blank task preview')
@section('content')
<header class="page-heading"><div><p class="eyebrow">{{ $catalogue['name'] }} · Block {{ $task['block'] }} · Task {{ $task['task'] }}</p><h1>{{ $task['title'] }}</h1><p>{{ $task['theme'] }}</p></div><div class="toolbar"><a class="button" href="{{ route('task-library.index',['programme'=>$key,'block'=>$task['block']]) }}">Back to task library</a><a class="button" href="{{ route('task-library.pdf',[$key,$task['id']]) }}">Download blank source PDF</a></div></header>
<p class="test-notice" id="blank-preview-note">Blank source preview only. No apprentice work is loaded or saved. Disabled fields show the source form; use an apprentice's assigned task to record work.</p>
@foreach($release['notices'] as $notice)<p class="notice"><strong>{{ $notice['topic'] }}</strong> {{ $notice['detail'] }}</p>@endforeach
<section class="panel"><h2>Task instructions</h2><p class="recorded-answer">{{ $template['instructions'] }}</p><p class="muted">{{ $task['id'] }} · Source revision {{ $template['version'] }}</p></section>
@foreach($template['supplementaryPages']??[] as $page)<details class="panel"><summary>Scenario / source material · page {{ $page['page'] }}</summary><p class="recorded-answer">{{ $page['text'] }}</p></details>@endforeach
@if($template['kind']==='knowledge')<section class="panel"><h2>Paper knowledge assessment result</h2><p>The assessment is completed and marked on paper. The portal records the actual title, marks achieved, marks available, tutor pass/refer decision and feedback. No online questions or automatic pass threshold are supplied.</p></section>@endif
@foreach(['apprentice'=>'Apprentice source fields','trainer'=>'Tutor source fields','observer'=>'Observer source fields'] as $role=>$heading)
@php($roleFields=collect($template['fields'])->where('role',$role))
@if($roleFields->isNotEmpty())<section class="panel"><h2>{{ $heading }}</h2><fieldset disabled aria-describedby="blank-preview-note">@include('learning.fields',['fields'=>$roleFields,'values'=>[],'readonly'=>false,'preview'=>true])</fieldset></section>@endif
@endforeach
<section class="panel"><h2>When assigned to an apprentice</h2><p>Non-paper tasks can be saved as drafts and submitted. The tutor approves the whole task or refers it for changes. Additional evidence and recorded-work PDFs remain available through the assigned task. Opening this preview creates no assignment, submission or approval.</p></section>
@endsection

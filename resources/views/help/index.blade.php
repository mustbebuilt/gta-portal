@extends('layout')
@section('title','Help and how to')
@section('content')
<header class="page-heading"><div><p class="eyebrow">Guides for your access</p><h1>Help and how to</h1><p>Open a short guide for the next step.</p></div><button type="button" data-stop-help hidden>Stop reading</button></header>
<p class="muted" data-voice-note>Optional read-aloud is available only when your device supports it. It reads these general help guides, never apprentice records. No microphone is used. Device voice services may process the guide text.</p>
@foreach($guides as $guide)<details class="panel"><summary>{{ $guide[1] }}</summary><p class="eyebrow">{{ $guide[0] }}</p><p id="help-guide-{{ $loop->index }}">{{ $guide[2] }}</p><button type="button" data-read-help="help-guide-{{ $loop->index }}" hidden>Read this guide aloud</button></details>@endforeach
<section class="panel"><h2>Need a correction?</h2><p>If the source information is missing or wrong, ask the authorised owner to check it. Do not enter a guessed result, date or achievement. This local development copy is not connected to live messages or external results services.</p></section>
@endsection

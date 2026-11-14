@extends('layout')
@section('title','Careers source needs checking')
@section('content')
<section class="panel"><h1>Careers source needs checking</h1><p>Careers action source needs administrator reconciliation before this discussion can be opened. No record has been changed.</p><p>{{ $apprentice->reference }} · Discussion {{ $discussionId }}</p><p>The saved action structure cannot be read reliably. Ask an administrator to check the original source. Do not recreate actions to bypass this check.</p><a class="button" href="{{ route('careers.worklist',['search'=>$apprentice->reference]) }}">Back to scoped Careers work</a></section>
@endsection

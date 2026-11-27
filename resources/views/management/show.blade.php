@extends('layout')
@section('title','Saved management pack')
@section('content')
<header class="page-heading"><div><h1>{{ $pack->title }}</h1><p>Captured by {{ $pack->snapshot['captured_actor']['name']??'Account '.$pack->captured_by }} · {{ $pack->created_at }}. Later source changes are not applied to this copy.</p></div><div class="toolbar"><a class="button" href="{{ route('management.index') }}">Current preview and saved packs</a><a class="button" href="{{ route('management.pdf',$pack) }}">Download fixed PDF</a></div></header>
@include('learning.errors')
@php($snapshot = $pack->snapshot)
@include('reports.headline', [
    'title' => 'Management snapshot',
    'basis' => 'Figures from the selected management snapshot, captured at its stated date and time.',
    'items' => [
        ["Apprentices", $snapshot['summary']['apprentices']],
        ["Awaiting marking", $snapshot['summary']['waiting_tasks']],
        ["Overdue marking", $snapshot['summary']['overdue_marking'], 'exceptions'],
        ["Apprentice records with checks", $snapshot['summary']['records_with_checks'], 'exceptions']
    ],
])
<section class="panel">@include('management.content',['snapshot'=>$pack->snapshot])</section><details><summary>Snapshot identifier</summary><p>{{ $pack->snapshot_hash }}</p></details>
@endsection

@extends('layout')
@section('title','Apprentice Register')
@section('content')
<header class="page-heading"><div><h1>Apprentice Register</h1><div class="toolbar"><a class="button" href="{{ route('training-gaps72.index') }}">Training gaps report</a></div></div></header>
@include('learning.errors')
@if(request()->filled('plan_date') || old('planner') === 'subjects')
    @include('attendance.plan-session')
@endif
@include('attendance.next-register83')
<details class="panel"><summary>Weekly groups and schedules</summary>@include('attendance.weekly-groups')</details>
@include('attendance.monthly-outstanding')
<details class="panel" @if(request()->filled('view')||request()->filled('search')||request()->filled('group')||request()->filled('apprentice'))open @endif><summary>Other sessions, filters and follow-up</summary>@include('attendance.register-worklist')</details>
@unless(request()->filled('plan_date') || old('planner') === 'subjects')
    @include('attendance.plan-session')
@endunless
@endsection

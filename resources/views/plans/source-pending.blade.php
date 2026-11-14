@extends('layout')
@section('title','Training-plan source required')
@section('content')<p><a class="button" href="{{ route('delivery.show',$apprentice) }}">Individual delivery plan and block calendar</a></p>

<header class="page-heading"><div><p class="eyebrow">{{ $apprentice->reference }}</p><h1>Training-plan source required</h1></div><a class="button" href="{{ route('apprentices.show',$apprentice) }}">Apprentice profile</a></header>
<section class="panel"><h2>Programme planning</h2><p>This programme needs its own controlled training-plan and KSB sources before formal source/KSB reconciliation or RPL assessment can be recorded here. The separate individual delivery-plan page supports operational dates, controlled references and independent provider review.</p><p>The loaded Autocare planning source does not apply. Assigned practical tasks, reflections and paper assessment results remain available in the training workspace.</p><a class="button primary" href="{{ route('learning.index',$apprentice) }}">Open training tasks</a></section>
@endsection

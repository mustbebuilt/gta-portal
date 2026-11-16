@extends('layout')
@section('title','Approved historical cohort report')
@section('content')
<h1>Approved historical cohort report</h1><p><a href="{{ route('historical-cohorts.show',$cohort) }}">Review evidence</a> · <a href="{{ route('historical-cohorts.download',$cohort) }}">Download aggregate JSON</a> · <a href="{{ route('historical-cohorts.pdf',$cohort) }}">Download report PDF</a></p><section class="panel"><div class="table-wrap">@include('historical-cohorts.summary')</div></section><p>Downloads contain staff names and aggregate counts, without apprentice identities, evidence references or private source bodies. Earlier downloaded copies remain historical copies if the report is later withdrawn.</p><p><a href="{{ route('historical-cohorts.index') }}">Back to historical comparisons</a></p>
@endsection

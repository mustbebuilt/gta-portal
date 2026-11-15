@extends('layout')
@section('title','Saved apprentice summary report')
@section('content')<h1>Saved apprentice summary report</h1><p>{{ $snapshot->id }} · {{ $body['report']['captured_at'] }} · cohort {{ $body['report']['from'] }} to {{ $body['report']['to'] }}. Captured current source; no live substitution.</p>@include('visuals.controls')
@foreach(\App\Support\PortalVisuals::cohort($body['report']) as $chart)@include('visuals.panel')@endforeach
@include('cohort-insights.counts',['counts'=>$body['report']['counts']])<p><a href="{{ route('cohort-insights.download',$snapshot) }}">Download summary data (JSON)</a> · <a href="{{ route('cohort-insights.index') }}">Apprentice progress report</a></p>@endsection

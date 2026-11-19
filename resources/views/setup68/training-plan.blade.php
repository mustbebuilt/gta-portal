@extends('layout')
@section('title','Training plan')
@section('content')
<h1>Training plan — {{$apprentice->first_name}} {{$apprentice->last_name}}</h1>
@if(!$plan)<p class="notice">The approved plan needs source reconciliation. Its previous records are retained; no replacement OTJ target has been assumed.</p>
@else<p><strong>Approved planned OTJ: {{number_format($plan['details']['target_hours'],2)}} hours</strong></p><p>Programme: {{$apprentice->programmeLabel()}}. Employer: {{$apprentice->employerLabel()}}.</p><div style="overflow:auto"><table><thead><tr><th>Block</th><th>Start</th><th>End</th><th>Planned OTJ hours</th></tr></thead><tbody>@foreach($plan['blocks'] as $b)<tr><td>{{$b['number']}} — {{$b['title']}}</td><td>{{$b['start']}}</td><td>{{$b['end']}}</td><td>{{number_format($b['hours'],2)}}</td></tr>@endforeach</tbody></table></div>@endif
@if($position)<p>Recorded prior learning removes {{number_format($position['recognised_hours']??0,2)}} planned training hours. This does not mark any KSB achieved or add delivered OTJ hours.</p>@endif
@if($setup)<a class="button" href="{{route('setup68.show',[$setup,4])}}">Open the same RPL / setup record</a>@endif
@endsection

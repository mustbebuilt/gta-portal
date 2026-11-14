@extends('pdf.layout')
@section('title','Recorded OTJ ledger')
@section('content')
<h1>Recorded OTJ ledger</h1><p>{{ $apprentice->reference }} · {{ $apprentice->first_name }} {{ $apprentice->last_name }} · {{ $summary['date'] }}</p>
@include('attendance.otj-summary')
<h2>Source records</h2>@forelse($rows as $row)<div class="record"><p>{{ $row['date'] }} · {{ $row['kind'] }} {{ $row['id'] }} · {{ $row['title'] }}</p><p>{{ $row['state'] }} · {{ number_format($row['minutes']/60,2) }} included hours · Cumulative {{ number_format($row['cumulative_minutes']/60,2) }}</p></div>@empty<p>No occurred source records.</p>@endforelse
@endsection

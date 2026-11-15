@extends('layout')
@section('title','Review appointments')
@section('content')
@include('learning.errors')
<h1>Review appointments</h1><p>Book from an apprentice’s review page link below. Appointments, availability and staff-reported outcomes do not complete the formal review or change its due date.</p>
@if(in_array(auth()->user()->role,['admin','ceo','tutor'],true)||\App\Support\StaffDuties::has(auth()->user(),'mentor'))<p><a href="{{ route('reviews.index') }}">Choose an apprentice from the review list</a> · <a href="{{ route('review-appointments.export',request()->except('page')) }}">Download staff status JSON</a></p>@endif
<form method="get"><label>From <input type="date" name="from" value="{{ $from }}"></label><label>To <input type="date" name="to" value="{{ $to }}"></label><label>State <select name="state"><option value="">All</option>@foreach(['Draft','Published','Reported','Cancelled'] as $s)<option @selected(($d['state']??'')===$s)>{{ $s }}</option>@endforeach</select></label><button>Filter</button></form>
@forelse($items  as $row)@php($p=$row['p'])<section class="panel"><h2><a href="{{ route('review-appointments.show',$p) }}">{{ $row['intact']?$p->source['basis']['name']:'Evidence needs checking' }}</a></h2><p>{{ $p->starts_at->copy()->setTimezone(config('app.timezone'))->format('d M Y H:i') }} · {{ config('app.timezone') }} · {{ $p->state }}</p>@if($row['staff'])<p>{{ $row['current']?'Named participant source current':'Participant source needs checking' }} · {{ $row['availability']['counts']['Available'] }}/3 available</p><p>{{ $row['followup']['label'] }}</p>@endif</section>@empty<p>No accessible appointments in this window.</p>@endforelse
{{ $items->links() }}
@endsection

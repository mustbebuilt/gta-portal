@extends('layout')
@section('title','Apprentice onboarding')
@section('content')
<h1>Apprentice onboarding</h1>@include('learning.errors')
<p>Check the correct person, link their own login, then help them get started. These setup checks do not confirm formal induction, eligibility, funding compliance or training completion.</p>
<form method="get"><label for="search">Name or apprentice reference</label><input id="search" name="search" value="{{ $search }}" maxlength="120"><label for="state">Account state</label><select id="state" name="state">@foreach(['all'=>'All apprentices','unlinked'=>'Login not linked','unconfirmed'=>'Linked mailbox unconfirmed','confirmed'=>'Linked mailbox confirmed'] as $value=>$label)<option value="{{ $value }}" @selected($state===$value)>{{ $label }}</option>@endforeach</select><button>Find apprentices</button></form>
@forelse($people as $a)@php($ready=$readiness[$a->id])<section class="panel"><h2><a href="{{ route('apprentice-onboarding.show',$a) }}">{{ $a->first_name }} {{ $a->last_name }}</a></h2><p>{{ $a->reference }} · {{ $ready['state'] }} · {{ $ready['confirmed'] }}/{{ $ready['total'] }} getting started checks</p>@if($ready['issues'])<ul>@foreach($ready['issues'] as $issue)<li>{{ $issue }}</li>@endforeach</ul>@else<p>Basic account and training setup checks passed. Check the remaining apprentice acknowledgements on their onboarding page.</p>@endif</section>@empty<p>No apprentices match these filters.</p>@endforelse
{{ $people->links() }}
@endsection

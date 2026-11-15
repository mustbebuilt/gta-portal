@extends('layout')
@section('title','Register follow-up actions')
@section('content')
<h1>Register follow-up actions</h1><p><a href="{{ route('session-operations.index') }}">Check current registers</a></p><p>Only current authorised register scope is shown. Historical stored state is separate from current source/owner checks. Old tutor access is not retained through an action.</p><form method="get"><label for="state">Stored state</label><select id="state" name="state">@foreach(['all','Open','Resolved','Deferred','Withdrawn'] as $value)<option @selected(($d['state']??'all')===$value)>{{ $value }}</option>@endforeach</select><button>Filter actions</button></form>@forelse($rows as $row)<section class="panel"><h2><a href="{{ route('register-actions.show',$row['session']) }}">{{ $row['session']->name }} · {{ $row['session']->session_date->format('d M Y') }}</a></h2><p>{{ $row['position']['label'] }}{{ $row['position']['overdue']?' · overdue':'' }} · review {{ $row['case']->due_on->format('d M Y') }}</p></section>@empty<p>No matching actions in your current register scope.</p>@endforelse
{{ $rows->links() }}
@endsection

@extends('layout')
@section('title','Staff caseload handovers')
@section('content')
<h1>Staff caseload handovers</h1>@include('learning.errors')<p>Move one selected responsibility for named apprentices, with an exact preview and a different manager's check. Existing history remains.</p><p><a class="button" href="{{ route('staff-handovers.workload') }}">Staff assignment overview</a> <a class="button" href="{{ route('staff-handovers.create') }}">Prepare a handover</a> <a href="{{ route('assignment-checks.index') }}">Check assignment gaps</a></p><form method="get"><label for="state">Preview status</label><select id="state" name="state">@foreach(['all','Pending','Applied','Rejected','Withdrawn'] as $value)<option @selected($state===$value)>{{ $value }}</option>@endforeach</select><button>Filter</button></form>
@forelse($batches as $b)<section class="panel"><h2><a href="{{ route('staff-handovers.show',$b) }}">{{ \App\Support\StaffHandover::SLOTS[$b->slot]??'Assignment evidence needs checking' }}</a></h2><p>{{ $b->state }} · prepared {{ $b->created_at }} · staff #{{ $b->from_user_id??'unassigned' }} → #{{ $b->target_user_id }} · creator #{{ $b->created_by }}</p></section>@empty<p>No handover previews in this view.</p>@endforelse
{{ $batches->links() }}
@endsection

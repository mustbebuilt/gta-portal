@extends('layout')
@section('title','My staff handovers')
@section('content')
<h1>My staff handovers</h1>@include('learning.errors')<p>Check incoming responsibilities against your current access. Only still-current assignments from each checked batch are named. Acknowledging this page does not mark work, sign reviews or confirm training.</p><p><a href="{{ route('responsibilities.index') }}">Open My responsibilities</a></p>
@forelse($batches as $b)@php($scope=$scopes[$b->id])@php($ack=$acks[$b->id])<section class="panel"><h2>{{ \App\Support\StaffHandover::SLOTS[$b->slot]??'Assignment needs checking' }}</h2><p>Applied {{ $b->reviewed_at }} UTC · reference {{ $b->id }}</p>@forelse($scope['rows'] as $row)<p>{{ $row['reference'] }} · {{ $row['name'] }}</p>@empty<p>No current visible assignments in this batch. Ask GTA if access or handover evidence needs checking.</p>@endforelse
@if($ack['acknowledged_at'])<p>Acknowledged {{ $ack['acknowledged_at'] }} UTC for these {{ count($scope['rows']) }} current visible assignments.</p>@elseif($ack['needs_recheck'])<p>Your assignment scope changed; check the current visible assignments again.</p>@else<p>Current scope not acknowledged.</p>@endif
@if($scope['rows'])<form method="post" action="{{ route('incoming-handovers.acknowledge',$b) }}">@csrf<input type="hidden" name="version" value="{{ $ack['version'] }}"><input type="hidden" name="scope_hash" value="{{ $scope['hash'] }}"><input type="hidden" name="confirmed" value="{{ $ack['acknowledged_at']?'0':'1' }}"><button>{{ $ack['acknowledged_at']?'Clear my acknowledgement':'I have checked these current responsibilities' }}</button></form>@endif</section>@empty<p>No applied staff handovers addressed to you.</p>@endforelse
{{ $batches->links() }}
@endsection

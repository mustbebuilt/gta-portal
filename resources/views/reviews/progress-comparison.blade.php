<div class="table-scroll">
<table>
<thead><tr><th scope="col">Area</th><th scope="col">Assigned learning completed</th><th scope="col">Whole applicable programme</th></tr></thead>
<tbody>
@foreach($summary['progress'] as $label => $progress)
<tr><th scope="row">{{ $label }}</th><td>{{ $progress['done'] }} / {{ $progress['total'] }}
@if($progress['percent'] !== null) · {{ $progress['percent'] }}% @endif
</td><td>@if(isset($progress['whole_percent']))
    {{ $progress['whole_done'] }} / {{ $progress['whole_total'] }} · {{ $progress['whole_percent'] }}%
@elseif(($progress['whole_total'] ?? null) === 0)
    No training required
@else
    Not available
@endif</td></tr>
@endforeach
</tbody>
</table>
</div>

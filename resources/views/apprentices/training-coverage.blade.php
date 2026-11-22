@if($trainingCoverage)
@php($covered = collect($trainingCoverage)->where('state','delivered')->count())
<details class="panel" id="training-coverage">
    <summary>Required training coverage · {{ $covered }} of {{ count($trainingCoverage) }} delivered</summary>
    <p>Delivery from submitted registers, including catch-up. Task approvals and qualification results are recorded separately.</p>
    <div class="table-wrap">
        <table>
            <thead><tr><th scope="col">Required topic</th><th scope="col">Coverage</th><th scope="col">Planned by</th></tr></thead>
            <tbody>
            @foreach(collect($trainingCoverage)->sortBy(fn ($topic) => match ($topic['state']) { 'overdue'=>0,'due'=>1,'partial'=>2,'delivered'=>4,default=>3 }) as $topic)
                <tr><th scope="row">{{ $topic['label'] }}</th>
                    <td>{{ match ($topic['state']) { 'delivered'=>'Delivered','overdue'=>'Catch-up overdue','partial'=>'Partially delivered','due'=>'Due today','planned'=>'Planned',default=>'Date not set' } }}
                        @if($topic['partial'] && $topic['state'] === 'overdue') · Part received @endif
                    </td><td>{{ $topic['due'] ?? 'Date not set' }}</td></tr>
            @endforeach
            </tbody>
        </table>
    </div>
</details>
@endif

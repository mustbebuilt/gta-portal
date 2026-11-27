@extends('layout')
@section('title','Tutor workload & register measures')
@section('content')
<link rel="stylesheet" href="{{ asset('assets/centre-planning.css') }}">
@include('learning.errors')
<h1>Tutor workload & register measures</h1>
@include('centre-planning.links')
<form method="get"><label>From <input type="date" name="from" value="{{ $report['from'] }}" required></label><label>To <input type="date" name="to" value="{{ $report['to'] }}" required></label><label>Programme <select name="programme"><option value="">All programmes</option>@foreach($programmes as $p)<option value="{{ $p->id }}" @selected($report['programme']===$p->id)>{{ $p->name }} · L{{ $p->level }}</option>@endforeach</select></label><button>Compare selected interval</button></form>
@include('reports.headline', [
    'title' => 'Register position',
    'basis' => 'Register outcomes in the selected reporting interval.',
    'items' => [
        ["Complete registers", $report['counts']['recorded']],
        ["Incomplete registers", $report['counts']['needs-register'], 'exceptions'],
        ["Register source checks", $report['counts']['checks'], 'exceptions'],
        ["Cancelled sessions", $report['counts']['cancelled']]
    ],
])
<p>Captured {{ $report['captured_at'] }}. Teaching dates count each date once, including part days; they are not full-day equivalents. Cover dates are included in confirmed delivery, not added again.</p>
<p>{{ $report['stale_plans'] }} stale planned teaching occurrence(s) excluded pending reconciliation.</p>
<p>Planned figures use current verified published planning only. Register figures use canonical records; missing or changed attribution remains unconfirmed. No delivery is inferred from the current group owner or the staff member entering marks.</p>
@foreach($report['source_warnings'] as $warning)<p role="alert">Planning source needs review: {{ $warning }}</p>@endforeach
<p>{{ $report['counts']['recorded'] }} complete register(s) · {{ $report['counts']['needs-register'] }} incomplete · {{ $report['counts']['cancelled'] }} cancelled · {{ $report['counts']['checks'] }} source check(s) · {{ $report['counts']['not-ended'] }} not ended. {{ $report['counts']['unattributed'] }} ended register(s) without current confirmed tutor attribution.</p>
<div class="planning-scroll" tabindex="0" aria-label="Tutor comparison table"><table class="planning-measures"><thead><tr><th scope="col">Tutor / programme</th><th scope="col">Planned dates / sessions</th><th scope="col">Confirmed dates / sessions</th><th scope="col">Cover dates</th><th scope="col">Confirmed attended teaching minutes</th><th scope="col">Unique roster apprentices</th><th scope="col">Average saved roster</th><th scope="col">Attendance in confirmed sessions</th></tr></thead><tbody>
@foreach($report['rows'] as $row)<tr><th scope="row">{{ $row['name'] }}<small>{{ implode(', ',array_keys($row['programmes']))?:'No verified teaching source in interval' }}</small></th><td>{{ count($row['planned_dates']) }} / {{ $row['planned_sessions'] }}</td><td>{{ count($row['delivery_dates']) }} / {{ $row['delivery_sessions'] }}</td><td>{{ count($row['cover_dates']) }}</td><td>{{ $row['delivered_minutes'] }}</td><td>{{ $row['unique_apprentices'] }}</td><td>{{ $row['average_roster']===null?'Unknown':number_format($row['average_roster'],1) }}</td><td>{{ $row['attendance']['percent']===null?'Unknown':number_format($row['attendance']['percent'],1).'%' }}<small>{{ $row['attendance']['attended'] }} / {{ $row['attendance']['expected'] }} expected; {{ $row['attendance']['excluded'] }} excluded codes</small></td></tr>@endforeach
</tbody></table></div>
<h2>Current apprentice progress and marking</h2>
<p>Current assigned caseload at capture time, filtered by the selected programme. These are current task figures, not reconstructed outcomes for the reporting interval. Approved tasks are not a qualification achievement rate. Compare similar programmes and delivery stages; inherited caseloads and support needs require context.</p>
<div class="planning-scroll" tabindex="0" aria-label="Current task progress"><table><thead><tr><th scope="col">Tutor</th><th scope="col">Current apprentices</th><th scope="col">Approved / assigned tasks</th><th scope="col">Overdue unfinished tasks</th><th scope="col">Awaiting marking</th><th scope="col">Overdue marking</th></tr></thead><tbody>@foreach($report['rows'] as $row)<tr><th scope="row">{{ $row['name'] }}</th><td>{{ $row['current_caseload'] }}</td><td>{{ $row['current_tasks']['approved'] }} / {{ $row['current_tasks']['assigned'] }}</td><td>{{ $row['current_tasks']['overdue_tasks'] }}</td><td>{{ $row['current_tasks']['awaiting'] }}</td><td>{{ $row['current_tasks']['overdue_marking'] }}</td></tr>@endforeach</tbody></table></div>
<h2>Planning priorities from existing records</h2>
<p>Suggestions for manager review; no duties or published classes are moved automatically.</p>
@forelse(array_filter($report['rows'],fn($r)=>$r['current_tasks']['overdue_marking']>0) as $row)<p>{{ $row['name'] }} has {{ $row['current_tasks']['overdue_marking'] }} overdue marking item(s). Review their <a href="{{ route('timetable-duties.flexible',['staff_id'=>$row['id']]) }}">Flexible Duties allocation</a> against any cover requirement.</p>@empty<p>No overdue marking shown for the selected current caseloads.</p>@endforelse
<h2>Register evidence and actual tutor attribution</h2>
<p>Use existing register recording first. Recorded actual arrival/departure times and an explicit break array are required; code-only legacy records remain unconfirmed. Confirm actual delivery only where supported by its ended, complete register, delivery details and actual teaching staff. This action records attribution; it never fills attendance or grants a cover tutor access to apprentice records. One confirmation per register remains valid until its source changes. Use a safe evidence reference, not private learner information.</p>
@forelse($report['registers'] as $session)<section class="panel"><h3>{{ $session['date'] }} · {{ $session['group'] }} · register {{ $session['id'] }}</h3><p>Register: {{ $session['state'] }} · {{ $session['confirmed']?'Delivery attributed to '.$session['confirmed']['staff_name']:'Delivery attribution unconfirmed' }}</p>
@if($canConfirm&&$session['can_confirm'])<details><summary>{{ $session['confirmed']?'Correct actual attribution':'Confirm actual teaching attribution' }}</summary><form method="post" action="{{ route('timetable-management.confirm',$session['id']) }}">@csrf
<input type="hidden" name="version" value="{{ $session['record_version'] }}"><input type="hidden" name="source_hash" value="{{ $session['source_hash'] }}">
<label>Actual delivering tutor (normal or historic) <select name="staff_id" required><option value="">Select actual tutor</option>@foreach($teachers as $teacher)<option value="{{ $teacher->id }}">{{ $teacher->name }}{{ $teacher->active?'':' (inactive — historic evidence only)' }}</option>@endforeach</select></label>
<label>Delivery type <select name="cover" required><option value="">Select actual type</option><option value="0">Normal teaching</option><option value="1">Teaching cover</option></select></label>
<label>Checked evidence / correction reason <textarea name="reason" rows="2" maxlength="1000" required></textarea></label><label><input type="checkbox" name="confirmed" value="1" required> I checked who actually delivered this session and the recorded delivery. Attendance and OTJ remain separate.</label><button>Record actual attribution</button></form></details>@endif</section>@empty<p>No register records in this interval.</p>@endforelse
@endsection

@php($snapshot = $review->source_snapshot)
@php($summary = $snapshot['review_summary'])
<style>body, table {font-size:12pt} h2 {margin:10px 0 4px} th,td {padding:4px} p {margin:4px 0}</style>
<h1>Apprentice Progress Review</h1>
<p>{{ $apprentice->first_name }} {{ $apprentice->last_name }} · {{ $apprentice->reference }} · {{ $review->state }} · Version {{ $review->version }}</p>
<p>Apprenticeship start {{ $snapshot['programme_dates']['start'] ?? 'Not recorded' }} · Practical finish {{ $snapshot['programme_dates']['practical_end'] ?? 'Not recorded' }} · Apprenticeship finish {{ $snapshot['programme_dates']['apprenticeship_end'] ?? 'Not recorded' }}</p>
<p>Actual meeting {{ $review->fields['reviewDate'] ?? 'Not recorded' }} · Next review {{ $summary['next_due'] ?? 'Not recorded' }}</p>
@include('reviews.progress-comparison')
@if(isset($summary['period_otj_minutes']))
<p>Recorded OTJ in the review period · {{ number_format($summary['period_otj_minutes']/60, 2) }} hours</p>
@endif
@if($summary['attendance'])
<p>Review-period attendance · {{ $summary['attendance']['attended'] }} attended · {{ $summary['attendance']['missed'] }} missed · {{ $summary['attendance']['unmarked'] }} unmarked</p>
@endif
@if(!empty($snapshot['formal_ksb_results']))
@php($ksbGroups = collect($snapshot['formal_ksb_results'])->countBy('outcome'))
<p>Formal KSB results recorded by the review date · @foreach($ksbGroups as $outcome => $count){{ $count }} {{ $outcome }}@unless($loop->last) · @endunless @endforeach</p>
@endif
@if(isset($snapshot['otj_position']['target_hours']))
<p>Agreed OTJ target · {{ number_format($snapshot['otj_position']['target_hours'],2) }} hours</p>
@endif
@if(isset($snapshot['otj_position']['recorded_minutes']))
<p>Recorded OTJ to capture date · {{ number_format($snapshot['otj_position']['recorded_minutes']/60,2) }} hours @if(isset($snapshot['otj_position']['progressive_expected_hours'])) · Expected {{ $snapshot['otj_position']['progressive_expected_hours'] }} hours @endif</p>
@endif
@foreach(['learning'=>'Learning and workplace application','attendance'=>'Employer contribution','judgement'=>'Progress judgement','previousNotes'=>'Previous actions and outcomes','summary'=>'Discussion and agreed next steps'] as $field=>$label)
@if(filled($review->fields[$field] ?? null))
<h2>{{ $label }}</h2><p class="answer">{{ $review->fields[$field] }}</p>
@endif
@endforeach
<h2>Outstanding dated tasks</h2>
@forelse($summary['outstanding'] as $task)
<p>Block {{ $task['block'] }} · {{ $task['title'] }} · {{ $task['due_date'] }}</p>
@empty<p>No dated outstanding tasks captured through the next review.</p>@endforelse
<h2>Source records</h2>
<p>{{ count($snapshot['delivered'] ?? []) }} attended delivery records · {{ count($snapshot['action_plan'] ?? []) }} captured actions. Open the saved portal review for the full record.</p>
@if($snapshot['monthly_snapshots'] ?? [])
<h2>Monthly development</h2>
@foreach($snapshot['monthly_snapshots'] as $month)
<p><strong>{{ $month['month'] }}</strong> · @if(isset($month['development']))
@foreach($month['development']['ratings'] as $key=>$rating){{ \App\Support\ApprenticeReviewData::DEVELOPMENT_AREAS[$key] ?? $key }}: {{ $rating }}@unless($loop->last) · @endunless @endforeach
@else {{ isset($month['missing']) ? 'Not recorded' : 'Legacy record available in the portal' }} @endif</p>
@if(filled($month['development']['comment'] ?? null))<p class="answer">{{ $month['development']['comment'] }}</p>@endif
@endforeach
@endif
@if($review->state === 'Ready for signatures')
<div style="page-break-inside:avoid">
<h2>Participant signatures</h2>
<p>Frozen source · Review {{ $review->id }} · Version {{ $review->version }} · {{ \App\Support\SignedReviews::reviewHash($review) }}</p>
@foreach(['Apprentice'=>$apprentice->first_name.' '.$apprentice->last_name,'Employer representative'=>$review->fields['employerRep']??'Not recorded','Learning & Progress Mentor'=>$review->fields['mentor']??'Not recorded'] as $role=>$name)
<p>{{ $role }} · {{ $name }} · Signature ____________________ · Date __________</p>
@endforeach
</div>
@endif

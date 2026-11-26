<section class="panel" aria-labelledby="record-overview-title">
    <h2 id="record-overview-title">At a glance</h2>
    <div class="metrics">
        @foreach(['Apprenticeship tasks'=>null, 'Maths'=>'maths', 'English'=>'english'] as $label => $subject)
            @php($item = $overview83['learning'][$label])
            <div class="metric">
                {{ $subject ? $label.' topics' : 'Tasks approved' }}
                <strong>{{ $item['percent'] === null ? 'Not available' : $item['percent'].'%' }}</strong>
                <small>{{ $item['done'] }} of {{ $item['total'] }} assigned {{ $subject ? 'topics complete' : 'tasks approved' }}</small>
                @if($item['whole_percent'] !== null)
                    <small>{{ $item['whole_percent'] }}% of applicable {{ $subject ? 'topics' : 'tasks' }}</small>
                @elseif($item['whole_total'] === 0)
                    <small>No training required in the approved plan</small>
                @endif
                <a href="{{ $subject ? route('em.index', [$apprentice, 'subject'=>$subject]) : route('learning.index', $apprentice) }}">{{ $subject ? 'Open '.$label : (auth()->user()->role === 'apprentice' ? 'My training tasks' : 'Open training tasks') }}</a>
            </div>
        @endforeach
        <div class="metric">
            Attendance
            <strong>{{ $overview83['attendance_check'] ? 'Needs checking' : (($overview83['attendance']['percent'] ?? null) !== null ? round($overview83['attendance']['percent']).'%' : 'Not available') }}</strong>
            <a href="{{ route('attendance.apprentice', $apprentice) }}">Open attendance</a>
        </div>
        @if($otjHeadline)
            <div class="metric">
                Recorded OTJ
                <strong>{{ $otjHeadline['recorded_hours'] === null ? 'Not available' : number_format($otjHeadline['recorded_hours'], 2).' hours' }}</strong>
                @if($otjHeadline['expected_hours'] !== null)
                    <small>{{ number_format($otjHeadline['expected_hours'], 2) }} hours expected to date</small>
                @endif
                @if($otjHeadline['gap_hours'] > 0)
                    <small>{{ number_format($otjHeadline['gap_hours'], 2) }} hours below expected to date</small>
                @endif
                @if($otjHeadline['needs_check'])<small>Some records need checking</small>@endif
                <a href="{{ route('otj.show', $apprentice) }}">Open OTJ record</a>
            </div>
        @endif
        <div class="metric">
            Review due
            <strong>{{ $overview83['review_due'] ?? 'Check review history' }}</strong>
            @if(auth()->user()->role !== 'apprentice' && ($next['kind'] ?? null) !== 'review')
                <a href="{{ \App\Support\PortalActions::destination(auth()->user(), $apprentice, 'reviews') }}">Progress reviews</a>
            @endif
        </div>
    </div>
</section>

@if(\App\Support\LearningAccess::manager(auth()->user())
    && !$approvedPlan && !$archived)
    @php($setupDraft = \App\Models\ApprenticeSetupDraft68::where('apprentice_id', $apprentice->id)->whereIn('state', ['Draft', 'Ready'])->latest()->first())
    <section class="panel">
        <h2>Training plan setup incomplete</h2>
        @if($setupDraft)
            <a class="button" href="{{ route('setup68.show', $setupDraft) }}">Continue setup</a>
        @else
            <form method="post" action="{{ route('setup68.resume-existing', $apprentice) }}">
                @csrf
                <button>Complete apprentice setup</button>
            </form>
        @endif
        <a href="{{ route('delivery.show', $apprentice) }}">Open individual delivery plan</a>
    </section>
@endif

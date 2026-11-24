<section class="panel" id="retention-early-warning">
    <h2>Early warning · apprentices to review</h2>
    <p>{{ $earlyWarning['warnings']->count() }} of {{ $earlyWarning['active'] }} apprentices with a confirmed active status have recorded signals.
        Recent tutor check-ins are available for {{ $earlyWarning['active_with_check_ins'] }}.
        {{ $earlyWarning['status_checks'] }} programme records need a status check.</p>
    <p>Review apprentices with several signals first. Signals are a recent tutor rating of Needs support, two consecutive sessions marked Absent or Kept at work through the previous Sunday, or a current retention marker. Missing check-ins and Not observed ratings are gaps in evidence. <a href="{{ route('weekly-retention.index') }}">Open the saved weekly warnings</a> for the Monday check and OTJ reminders.</p>
    @forelse($earlyWarning['warnings'] as $row)
        @php($evidence = $row['early_warning'])
        <article class="work-row">
            <div>
                <h3><a href="{{ route('apprentices.show', $row['apprentice']) }}">{{ $row['apprentice']->first_name }} {{ $row['apprentice']->last_name }} · {{ $row['apprentice']->reference }}</a></h3>
                @foreach($evidence['needs_support'] as $area => $observation)
                    <p>{{ \App\Support\ApprenticeReviewData::DEVELOPMENT_AREAS[$area] }} · Needs support in {{ $observation['month'] }}
                        @if($observation['support_months'] > 1) · recorded in {{ $observation['support_months'] }} months @endif
                        · <a href="{{ route('attendance.show', $observation['session_id']) }}">Source register</a>
                    </p>
                @endforeach
                @if($evidence['current_attendance'])
                    <p>Two consecutive missed sessions ·
                        @foreach($evidence['current_attendance'] as $miss)
                            {{ $miss['date'] }} {{ \App\Support\AttendanceMath::CODES[$miss['code']] }} ·
                        @endforeach
                        <a href="{{ route('attendance.index', ['apprentice' => $row['apprentice']->id]) }}">View registers</a>
                    </p>
                @endif
                @foreach($evidence['markers'] as $code)
                    <p>Current retention marker · {{ $markerLabels[$code] ?? $code }}</p>
                @endforeach
                @unless($evidence['latest'])
                    <p>No recent dated tutor check-in.</p>
                @endunless
            </div>
            <a class="button" href="{{ route('support.index', $row['apprentice']) }}">Review support record</a>
        </article>
    @empty
        <p>No confirmed active apprentices in this cohort meet the current review rules.</p>
    @endforelse
</section>

<section class="panel" id="retention-outcome-themes">
    <h2>Evidence before early leaving and achievement</h2>
    <p>Compares evidence recorded before the programme ended. Tutor ratings use the latest observation for each area from the final 60 days. The historical non-attendance measure covers two completed weeks and includes recorded sickness and approved holiday. Figures show associations for staff to investigate.</p>
    <div class="table-scroll"><table>
        <thead><tr><th scope="col">Evidence</th>
            @foreach($earlyWarning['outcomes'] as $label => $outcome)
                <th scope="col">{{ $label }} ({{ $outcome['total'] }})</th>
            @endforeach
        </tr></thead>
        <tbody>
            @foreach(['check_ins' => 'Apprentices with a dated tutor check-in', 'attendance' => 'Historical two-week non-attendance measure', 'markers' => 'Retention marker in place at exit'] as $key => $label)
                <tr><th scope="row">{{ $label }}</th>@foreach($earlyWarning['outcomes'] as $outcome)<td>{{ $outcome[$key] }} / {{ $outcome['total'] }}</td>@endforeach</tr>
            @endforeach
            @foreach(\App\Support\ApprenticeReviewData::DEVELOPMENT_AREAS as $area => $label)
                <tr><th scope="row">{{ $label }}</th>
                @foreach($earlyWarning['outcomes'] as $outcome)
                    @php($rating = $outcome['areas'][$area])
                    <td>{{ $rating['needs_support'] }} needed support · {{ $rating['consistent'] }} consistently demonstrated · {{ $rating['observed'] }} observed</td>
                @endforeach
                </tr>
            @endforeach
        </tbody>
    </table></div>
    @if($earlyWarning['leaving_reasons']->isNotEmpty())
        <h3>Recorded early-leaver reasons</h3>
        <ul>
            @foreach($earlyWarning['leaving_reasons'] as $reason => $count)
                <li>{{ $reason }} · {{ $count }}</li>
            @endforeach
        </ul>
    @endif
</section>

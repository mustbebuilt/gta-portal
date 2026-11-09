<x-portal-layout>
    <div style="margin-bottom: 24px; display: flex; justify-content: space-between; align-items: start; flex-wrap: wrap; gap: 16px;">
        <div>
            <span class="badge badge-red" style="margin-bottom: 6px;">Staff Oversight &bull; Priority Watchlist</span>
            <h2 style="font-size: 22px; font-weight: 700; color: var(--gta-text);">Needs Attention</h2>
            <p style="font-size: 13px; color: var(--gta-muted); margin-top: 4px;">Choose an item and follow the next step. Action overdue marking, attendance risks, and review deadlines.</p>
        </div>
        <button type="button" onclick="window.print()" class="gta-btn gta-btn-secondary" style="font-size: 13px;">Print / Save PDF</button>
    </div>

    <!-- Alert Counts Strip -->
    <div class="gta-stat-grid" style="margin-bottom: 24px;">
        <div class="gta-stat-card">
            <small>Total Follow-up Items</small>
            <strong style="color: {{ $totalIssuesCount > 0 ? '#b91c1c' : '#15803d' }};">{{ $totalIssuesCount }}</strong>
        </div>
        <div class="gta-stat-card">
            <small>Overdue Marking (>7 Days)</small>
            <strong style="color: {{ $overdueMarking->count() > 0 ? '#b91c1c' : '#15803d' }};">{{ $overdueMarking->count() }}</strong>
        </div>
        <div class="gta-stat-card">
            <small>Attendance RAG Flags</small>
            <strong style="color: {{ count($attendanceRisks) > 0 ? '#b45309' : '#15803d' }};">{{ count($attendanceRisks) }}</strong>
        </div>
        <div class="gta-stat-card">
            <small>Overdue SMART Actions</small>
            <strong style="color: {{ $overdueActions->count() > 0 ? '#b91c1c' : '#15803d' }};">{{ $overdueActions->count() }}</strong>
        </div>
    </div>

    <!-- Follow-up Items Tables -->
    <div style="display: flex; flex-direction: column; gap: 24px;">

        <!-- 1. Overdue Marking Table -->
        <div class="gta-card">
            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 14px; border-bottom: 1px solid var(--gta-border); padding-bottom: 10px;">
                <h3 style="font-size: 16px; font-weight: 700;">Overdue Task Marking (7-Day SLA Breached)</h3>
                <span class="badge badge-red">{{ $overdueMarking->count() }} Tasks</span>
            </div>

            @if($overdueMarking->isEmpty())
                <p style="font-size: 13px; color: var(--gta-muted); padding: 12px 0;">All submitted tasks are currently marked within the 7-calendar-day SLA.</p>
            @else
                <table class="gta-table">
                    <thead>
                        <tr>
                            <th>Learner</th>
                            <th>Block &amp; Task</th>
                            <th>Submitted</th>
                            <th>Days Waiting</th>
                            <th>Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($overdueMarking as $task)
                            @php
                                $days = $task->submitted_at ? $task->submitted_at->diffInDays(now()) : 0;
                            @endphp
                            <tr>
                                <td><strong>{{ $task->apprentice->user->full_name }}</strong></td>
                                <td>Block {{ $task->ablk_id }} &bull; {{ $task->task_type }}</td>
                                <td>{{ $task->submitted_at ? $task->submitted_at->format('d M Y') : 'N/A' }}</td>
                                <td><span class="badge badge-red">{{ $days }} days (Overdue)</span></td>
                                <td>
                                    <a href="{{ route('marking.index') }}" class="gta-btn gta-btn-secondary" style="font-size: 11px; padding: 4px 10px;">Mark Task &rarr;</a>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            @endif
        </div>

        <!-- 2. Attendance RAG Risks Table -->
        <div class="gta-card">
            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 14px; border-bottom: 1px solid var(--gta-border); padding-bottom: 10px;">
                <h3 style="font-size: 16px; font-weight: 700;">Attendance Monitoring Flags (&lt;95% Target)</h3>
                <span class="badge badge-amber">{{ count($attendanceRisks) }} Learners</span>
            </div>

            @if(empty($attendanceRisks))
                <p style="font-size: 13px; color: var(--gta-muted); padding: 12px 0;">All cohort learners are attending at or above 95% Green standard.</p>
            @else
                <table class="gta-table">
                    <thead>
                        <tr>
                            <th>Learner</th>
                            <th>Employer</th>
                            <th>Sessions Attended</th>
                            <th>Attendance %</th>
                            <th>RAG Status</th>
                            <th>Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($attendanceRisks as $risk)
                            @php
                                $app = $risk['apprentice'];
                                $rag = $risk['rag'];
                            @endphp
                            <tr>
                                <td><strong>{{ $app->user->full_name }}</strong></td>
                                <td>{{ $app->employer?->employer_name ?? 'GTA Partner' }}</td>
                                <td>{{ $rag['attended_sessions'] }} / {{ $rag['total_sessions'] }}</td>
                                <td><strong>{{ $rag['percentage'] ?? 0 }}%</strong></td>
                                <td>
                                    <span class="badge {{ $rag['badge_class'] }}" style="{{ $rag['badge_style'] }}">{{ $rag['rating'] }}</span>
                                </td>
                                <td>
                                    <a href="{{ route('attendance.apprentice', $app->apprentice_id) }}" class="gta-btn gta-btn-secondary" style="font-size: 11px; padding: 4px 10px;">View Register &rarr;</a>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            @endif
        </div>

        <!-- 3. Overdue Actions Table -->
        <div class="gta-card">
            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 14px; border-bottom: 1px solid var(--gta-border); padding-bottom: 10px;">
                <h3 style="font-size: 16px; font-weight: 700;">Overdue SMART Action Plan Targets</h3>
                <span class="badge badge-red">{{ $overdueActions->count() }} Targets</span>
            </div>

            @if($overdueActions->isEmpty())
                <p style="font-size: 13px; color: var(--gta-muted); padding: 12px 0;">No agreed action targets are past deadline.</p>
            @else
                <table class="gta-table">
                    <thead>
                        <tr>
                            <th>Learner</th>
                            <th>Target / Action</th>
                            <th>Owner</th>
                            <th>Target Deadline</th>
                            <th>Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($overdueActions as $item)
                            <tr>
                                <td><strong>{{ $item->actionPlan->apprentice->user->full_name ?? 'Learner' }}</strong></td>
                                <td>{{ $item->action_description }}</td>
                                <td><span class="badge badge-gray">{{ $item->owner_role }}</span></td>
                                <td><strong style="color: #b91c1c;">{{ $item->target_date ? $item->target_date->format('d M Y') : 'N/A' }}</strong></td>
                                <td>
                                    <a href="{{ route('action-plan.index', ['apprentice' => $item->actionPlan->apprentice_id ?? 1]) }}" class="gta-btn gta-btn-secondary" style="font-size: 11px; padding: 4px 10px;">Open Action Plan &rarr;</a>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            @endif
        </div>
    </div>
</x-portal-layout>


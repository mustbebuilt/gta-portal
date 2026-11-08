<x-portal-layout>
    <div style="margin-bottom: 24px;">
        <h2 style="font-size: 22px; font-weight: 700; color: #0f172a;">Staff Overview &amp; Caseload</h2>
        <p style="font-size: 13px; color: #64748b; margin-top: 4px;">Welcome back, {{ Auth::user()->full_name }}. Here is your current training activity and compliance status.</p>
    </div>

    <!-- Stat Metrics Grid -->
    <div class="gta-stat-grid">
        <div class="gta-stat-card">
            <small>Active Apprentices</small>
            <strong>{{ $totalApprentices }}</strong>
        </div>
        <div class="gta-stat-card">
            <small>Pending Marking</small>
            <strong style="color: #b45309;">{{ $pendingMarking }}</strong>
        </div>
        <div class="gta-stat-card">
            <small>SLA Overdue (&gt; 7 Days)</small>
            <strong style="color: #b91c1c;">{{ $overdueMarking }}</strong>
        </div>
        <div class="gta-stat-card">
            <small>Approved Case Studies</small>
            <strong style="color: #15803d;">{{ $approvedCaseStudies }}</strong>
        </div>
    </div>

    <!-- Submissions Waiting Decision -->
    <div class="gta-card">
        <header>
            <div>
                <h3 style="font-size: 16px; font-weight: 700;">Recent Submissions Waiting Review</h3>
                <p style="font-size: 12px; color: #64748b; margin-top: 2px;">Tasks awaiting whole-task approval by assigned GTA tutor.</p>
            </div>
            <a href="{{ route('marking.index') }}" style="font-size: 13px; color: #254c6a; font-weight: 600; text-decoration: none;">View Full Marking Queue &rarr;</a>
        </header>

        @if($recentSubmissions->isEmpty())
            <div style="padding: 24px; text-align: center; color: #64748b; font-size: 13px;">
                No pending submissions waiting for decision.
            </div>
        @else
            <table class="gta-table">
                <thead>
                    <tr>
                        <th>Apprentice</th>
                        <th>Block &amp; Task</th>
                        <th>Submitted At</th>
                        <th>Waiting</th>
                        <th>SLA Rating</th>
                        <th>Action</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($recentSubmissions as $submission)
                        @php
                            $days = $submission->submitted_at ? $submission->submitted_at->diffInDays(now()) : 0;
                            $overdue = $days > 7;
                        @endphp
                        <tr>
                            <td><strong>{{ $submission->apprentice->user->full_name }}</strong></td>
                            <td>Block {{ $submission->ablk_id }} &bull; {{ $submission->task_type }}</td>
                            <td>{{ $submission->submitted_at ? $submission->submitted_at->format('d M Y') : 'N/A' }}</td>
                            <td>{{ $days }} {{ Str::plural('day', $days) }}</td>
                            <td>
                                @if($overdue)
                                    <span class="badge badge-red">Overdue ({{ $days }}d)</span>
                                @else
                                    <span class="badge badge-green">Within SLA</span>
                                @endif
                            </td>
                            <td>
                                <a href="{{ route('marking.index') }}" style="font-size: 12px; color: #254c6a; font-weight: 600; text-decoration: underline;">Review</a>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        @endif
    </div>
</x-portal-layout>


<x-portal-layout>
    <div style="margin-bottom: 24px; display: flex; justify-content: space-between; align-items: start; flex-wrap: wrap; gap: 16px;">
        <div>
            <div style="display: flex; align-items: center; gap: 8px; margin-bottom: 6px;">
                <span class="badge badge-amber">Executive &amp; Auditor Access</span>
                <span style="font-size: 11px; color: #64748b;">Build 2026.11.08.6 &bull; Verified Canonical Records</span>
            </div>
            <h2 style="font-size: 22px; font-weight: 700; color: #0f172a;">Ofsted &amp; Quality Inspection Overview</h2>
            <p style="font-size: 13px; color: #64748b; margin-top: 4px;">
                Read-only aggregation of active training standards, tripartite review signing, attendance RAG ratings, and peer-approved case studies.
            </p>
        </div>
        <div>
            <a href="{{ route('inspection.print') }}" target="_blank" style="display: inline-block; padding: 10px 18px; background: #254c6a; color: #fff; text-decoration: none; border-radius: 6px; font-size: 13px; font-weight: 600;">
                &#128424; Print Inspection Dossier
            </a>
        </div>
    </div>

    <!-- Core Inspection Metrics Banner -->
    <div class="gta-stat-grid">
        <div class="gta-stat-card">
            <small>Active Learners</small>
            <strong>{{ $totalLearners }}</strong>
            <span style="font-size: 11px; color: #64748b;">ST0482 Autocare Technician</span>
        </div>
        <div class="gta-stat-card">
            <small>Attendance RAG Distribution</small>
            <div style="display: flex; gap: 6px; margin-top: 8px; flex-wrap: wrap;">
                <span class="badge badge-green">{{ $attendanceGreen }} Green</span>
                <span class="badge badge-amber">{{ $attendanceAmber }} Amber</span>
                <span class="badge badge-red">{{ $attendanceRed }} Red</span>
                @if($attendanceUnknown > 0)
                    <span class="badge" style="background: #f1f5f9; color: #64748b;">{{ $attendanceUnknown }} Unknown</span>
                @endif
            </div>
        </div>
        <div class="gta-stat-card">
            <small>Tripartite Review Signing Rate</small>
            <strong style="color: {{ $reviewSigningRate >= 90 ? '#15803d' : '#b45309' }};">{{ $reviewSigningRate }}%</strong>
            <span style="font-size: 11px; color: #64748b;">{{ $signedReviews }} / {{ $totalReviews }} Reviews 3-Party Signed</span>
        </div>
        <div class="gta-stat-card">
            <small>Verified OTJ Training</small>
            <strong style="color: #254c6a;">{{ number_format($totalVerifiedOtjHours, 1) }} hrs</strong>
            <span style="font-size: 11px; color: #64748b;">Avg {{ number_format($averageOtjPerLearner, 1) }} hrs / learner</span>
        </div>
    </div>

    <!-- Live Cohort Evidence Roster -->
    <div class="gta-card">
        <header>
            <h3 style="font-size: 16px; font-weight: 700;">Learner Milestone &amp; Quality Compliance Roster</h3>
            <span style="font-size: 12px; color: #64748b;">{{ $totalLearners }} Enrolled Apprentices</span>
        </header>

        <table class="gta-table">
            <thead>
                <tr>
                    <th>Apprentice</th>
                    <th>Employer</th>
                    <th>Assigned Tutor</th>
                    <th>Tasks Done (out of 50)</th>
                    <th>OTJ Verified</th>
                    <th>Reviews Status</th>
                    <th>Action</th>
                </tr>
            </thead>
            <tbody>
                @foreach($apprentices as $app)
                    @php
                        $doneTasks = $app->tasks->where('status', 'Completed')->count();
                        $signedRevs = $app->progressReviews->where('status', 'Signed')->count();
                        $totalRevs = $app->progressReviews->count();
                    @endphp
                    <tr>
                        <td><strong>{{ $app->user->full_name }}</strong></td>
                        <td>{{ $app->employer?->employer_name ?? 'GTA Partner' }}</td>
                        <td>{{ $app->trainer?->full_name ?? 'Alex Taylor' }}</td>
                        <td>
                            <strong>{{ $doneTasks }} / 50</strong>
                            <div style="width: 80px; height: 6px; background: #e2e8f0; border-radius: 9999px; margin-top: 4px; overflow: hidden;">
                                <div style="width: {{ ($doneTasks / 50) * 100 }}%; height: 100%; background: #254c6a;"></div>
                            </div>
                        </td>
                        <td>
                            <strong>{{ number_format($app->otj_hours_completed, 1) }} hrs</strong>
                            <small style="display: block; color: #64748b; font-size: 10px;">Target: {{ number_format($app->otj_target_hours, 0) }}h</small>
                        </td>
                        <td>
                            @if($totalRevs > 0)
                                <span class="badge {{ $signedRevs === $totalRevs ? 'badge-green' : 'badge-amber' }}">
                                    {{ $signedRevs }} / {{ $totalRevs }} Signed
                                </span>
                            @else
                                <span style="font-size: 11px; color: #94a3b8;">Pending Review #1</span>
                            @endif
                        </td>
                        <td>
                            <a href="{{ route('journey.show', $app->apprentice_id) }}" style="font-size: 12px; color: #254c6a; font-weight: 600; text-decoration: underline;">
                                View Journey &rarr;
                            </a>
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>

    <!-- Approved Anonymous Case Studies -->
    <div class="gta-card">
        <header>
            <div>
                <h3 style="font-size: 16px; font-weight: 700;">Approved Qualitative Case Study Evidence Bank</h3>
                <p style="font-size: 12px; color: #64748b; margin-top: 2px;">Published case studies verified under the different-administrator rule.</p>
            </div>
            <a href="{{ route('case-studies.index') }}" style="font-size: 13px; color: #254c6a; font-weight: 600; text-decoration: none;">View Full Bank &rarr;</a>
        </header>

        @if($approvedCaseStudies->isEmpty())
            <div style="padding: 24px; text-align: center; color: #64748b; font-size: 13px;">
                No case studies currently approved. Use the Case Studies module to author and peer-approve qualitative evidence.
            </div>
        @else
            <table class="gta-table">
                <thead>
                    <tr>
                        <th>Category</th>
                        <th>Case Study Title</th>
                        <th>Before &rarr; After Progress Impact</th>
                        <th>Primary Outcome</th>
                        <th>Peer Approver</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($approvedCaseStudies as $case)
                        <tr>
                            <td><span class="badge badge-blue">{{ $case->category }}</span></td>
                            <td>
                                <a href="{{ route('case-studies.show', $case->id) }}" style="color: #254c6a; font-weight: 600; text-decoration: none;">
                                    {{ $case->title }}
                                </a>
                            </td>
                            <td>
                                <small style="color: #b91c1c;">{{ $case->before_state }}</small> &rarr;
                                <strong style="color: #15803d;">{{ $case->after_state }}</strong>
                            </td>
                            <td><strong>{{ $case->outcome }}</strong></td>
                            <td><small style="color: #15803d; font-weight: 600;">{{ $case->approver?->full_name ?? 'Administrator' }}</small></td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        @endif
    </div>
</x-portal-layout>

<x-portal-layout>
    <div style="margin-bottom: 24px; display: flex; justify-content: space-between; align-items: start; flex-wrap: wrap; gap: 16px;">
        <div>
            <span class="badge badge-purple" style="margin-bottom: 6px;">GTA Reporting &bull; Management Suite</span>
            <h2 style="font-size: 22px; font-weight: 700; color: var(--gta-text);">Reporting Home &amp; Directory</h2>
            <p style="font-size: 13px; color: var(--gta-muted); margin-top: 4px;">Provider-wide compliance, statutory RAG metrics, and operational report directory.</p>
        </div>
        <div style="display: flex; gap: 10px;">
            <a href="{{ route('inspection.print') }}" target="_blank" class="gta-btn gta-btn-secondary" style="font-size: 13px; text-decoration: none;">Print Management Pack</a>
        </div>
    </div>

    <!-- Cohort Filter -->
    <div class="gta-card" style="margin-bottom: 20px; padding: 14px;">
        <form method="GET" action="{{ route('reports.index') }}" style="display: flex; gap: 14px; align-items: center; flex-wrap: wrap;">
            <label style="font-size: 13px; font-weight: 600;">Cohort / Group Filter:</label>
            <select name="group_id" onchange="this.form.submit()" class="form-control" style="padding: 7px 12px; font-size: 13px; border: 1px solid var(--gta-border); border-radius: 6px; min-width: 240px;">
                <option value="">All Cohorts (Provider-wide)</option>
                @foreach($tutorGroups as $group)
                    <option value="{{ $group->tutor_group_id }}" {{ $groupId == $group->tutor_group_id ? 'selected' : '' }}>
                        {{ $group->group_name }} ({{ $group->group_code }})
                    </option>
                @endforeach
            </select>
            @if($groupId)
                <a href="{{ route('reports.index') }}" style="font-size: 12px; color: var(--gta-muted); text-decoration: underline;">Clear Filter</a>
            @endif
        </form>
    </div>

    <!-- Executive KPI Grid -->
    <div class="gta-stat-grid" style="margin-bottom: 24px;">
        <div class="gta-stat-card">
            <small>Active Apprentices</small>
            <strong>{{ $totalLearners }}</strong>
        </div>
        <div class="gta-stat-card">
            <small>Attendance RAG (&ge;95% Green)</small>
            <div style="display: flex; gap: 8px; align-items: baseline; margin-top: 6px;">
                <span style="font-size: 18px; font-weight: 700; color: #15803d;">{{ $greenAttendance }} G</span> &bull;
                <span style="font-size: 18px; font-weight: 700; color: #b45309;">{{ $amberAttendance }} A</span> &bull;
                <span style="font-size: 18px; font-weight: 700; color: #b91c1c;">{{ $redAttendance }} R</span>
            </div>
        </div>
        <div class="gta-stat-card">
            <small>OTJ Training Hours</small>
            <strong style="color: #15803d;">{{ $onTrackOtj }} On Track</strong>
            <span style="font-size: 11px; color: var(--gta-muted); margin-top: 2px;">{{ $behindOtj }} Behind Pace</span>
        </div>
        <div class="gta-stat-card">
            <small>7-Day Marking SLA</small>
            <strong style="color: {{ $overdueMarking > 0 ? '#b91c1c' : '#15803d' }};">
                {{ $pendingMarking }} Pending
            </strong>
            <span style="font-size: 11px; color: {{ $overdueMarking > 0 ? '#b91c1c' : 'var(--gta-muted)' }};">
                {{ $overdueMarking }} Overdue (>7d)
            </span>
        </div>
    </div>

    <!-- Report Directory Section -->
    <div class="gta-card">
        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 16px; border-bottom: 1px solid var(--gta-border); padding-bottom: 12px;">
            <div>
                <h3 style="font-size: 17px; font-weight: 700;">Statutory &amp; Operational Report Directory</h3>
                <p style="font-size: 12px; color: var(--gta-muted); margin-top: 2px;">Select a reporting area to inspect live canonical records, apply filters, and generate audit-ready documentation.</p>
            </div>
            <input type="text" id="reportSearchInput" onkeyup="filterReports()" placeholder="Search reports..." class="form-control" style="padding: 7px 12px; font-size: 12px; border: 1px solid var(--gta-border); border-radius: 6px; width: 220px;">
        </div>

        <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(320px, 1fr)); gap: 16px;" id="reportsGrid">
            @foreach($reportsDirectory as $rep)
                <div class="report-card" data-title="{{ strtolower($rep['title']) }}" data-cat="{{ strtolower($rep['category']) }}" style="padding: 16px; background: var(--gta-bg); border-radius: 8px; border: 1px solid var(--gta-border); display: flex; flex-direction: column; justify-content: space-between;">
                    <div>
                        <div style="display: flex; justify-content: space-between; align-items: start; margin-bottom: 8px;">
                            <span style="font-size: 24px;">{{ $rep['icon'] }}</span>
                            <span class="badge badge-blue" style="font-size: 10px;">{{ $rep['category'] }}</span>
                        </div>
                        <h4 style="font-size: 15px; font-weight: 700; color: var(--gta-text); margin-bottom: 6px;">{{ $rep['title'] }}</h4>
                        <p style="font-size: 12px; color: var(--gta-muted); line-height: 1.5; margin-bottom: 12px;">{{ $rep['description'] }}</p>
                    </div>
                    <div>
                        <a href="{{ $rep['route'] }}" class="gta-btn gta-btn-secondary" style="font-size: 12px; width: 100%; text-align: center; display: block; text-decoration: none;">
                            Open Report &rarr;
                        </a>
                    </div>
                </div>
            @endforeach
        </div>
    </div>

    <script>
        function filterReports() {
            const input = document.getElementById('reportSearchInput').value.toLowerCase();
            const cards = document.querySelectorAll('.report-card');
            cards.forEach(card => {
                const title = card.getAttribute('data-title');
                const cat = card.getAttribute('data-cat');
                if (title.includes(input) || cat.includes(input)) {
                    card.style.display = 'flex';
                } else {
                    card.style.display = 'none';
                }
            });
        }
    </script>
</x-portal-layout>


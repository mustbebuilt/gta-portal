<x-portal-layout>
    <div class="ap-head" style="margin-bottom: 24px;">
        <div>
            <span class="sim-badge">Administration &bull; Leadership Command Hub</span>
            <h1 style="font-size: 26px; font-weight: 700; margin-top: 4px; color: var(--gta-text);">Administrator Center</h1>
            <p style="font-size: 13px; color: var(--gta-muted); margin-top: 4px;">Comprehensive administrative controls for learner enrollment, cohort management, staff CPD, careers guidance, and statutory reporting.</p>
        </div>
        <div class="sim-actions" style="display: flex; gap: 10px; flex-wrap: wrap;">
            <a href="{{ route('admin.apprentices.create') }}" class="gta-btn gta-btn-primary" style="text-decoration: none; font-size: 13px; font-weight: 700;">
                + Add Apprentice
            </a>
            <a href="{{ route('admin.groups.index') }}" class="gta-btn gta-btn-secondary" style="text-decoration: none; font-size: 13px;">
                Manage Groups
            </a>
        </div>
    </div>

    <!-- Quick Metrics Strip matching preview -->
    <div class="gta-stat-grid" style="margin-bottom: 24px;">
        <div class="gta-stat-card">
            <small>Active Apprentices</small>
            <strong style="color: #15803d;">{{ $liveApprentices }}</strong>
            <span style="font-size: 11px; color: var(--gta-muted);">{{ $totalApprentices }} Total Registered</span>
        </div>
        <div class="gta-stat-card">
            <small>Tutor Cohorts</small>
            <strong style="color: #254c6a;">{{ $totalGroups }}</strong>
            <span style="font-size: 11px; color: var(--gta-muted);">Active workshop groups</span>
        </div>
        <div class="gta-stat-card">
            <small>Teaching &amp; Quality Staff</small>
            <strong style="color: #254c6a;">{{ $totalStaff }}</strong>
            <span style="font-size: 11px; color: var(--gta-muted);">Tutors, Mentors, Admins</span>
        </div>
        <div class="gta-stat-card">
            <small>Careers Discussions</small>
            <strong style="color: #7059d3;">{{ $careerCount }}</strong>
            <span style="font-size: 11px; color: var(--gta-muted);">Recorded milestones</span>
        </div>
    </div>

    <!-- Admin Features Grid -->
    <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(340px, 1fr)); gap: 20px; margin-bottom: 30px;">

        <!-- 1. Apprentices List & Add Apprentice -->
        <div class="panel" style="padding: 20px; display: flex; flex-direction: column; justify-content: space-between;">
            <div>
                <div style="display: flex; justify-content: space-between; align-items: start; margin-bottom: 10px;">
                    <span class="sim-badge">Programme &amp; Staff Setup</span>
                    <span style="font-size: 20px;">🎓</span>
                </div>
                <h3 style="font-size: 18px; font-weight: 700; color: #07366f; margin-bottom: 8px;">Apprentices List &amp; Add Apprentice</h3>
                <p style="font-size: 13px; color: var(--gta-muted); line-height: 1.5; margin-bottom: 16px;">
                    Search and filter enrolled apprentices, inspect individual RAG attendance metrics, allocate standard frameworks, and onboard new apprentices with automatic 50-task curriculum generation across 10 blocks.
                </p>
            </div>
            <div style="display: flex; gap: 10px; flex-wrap: wrap;">
                <a href="{{ route('admin.apprentices.index') }}" class="gta-btn gta-btn-secondary" style="flex: 1; text-align: center; text-decoration: none; font-size: 12px; font-weight: 700;">
                    Apprentices List &rarr;
                </a>
                <a href="{{ route('admin.apprentices.create') }}" class="gta-btn gta-btn-primary" style="flex: 1; text-align: center; text-decoration: none; font-size: 12px; font-weight: 700;">
                    + Add Apprentice
                </a>
            </div>
        </div>

        <!-- 2. Group Creation & Tutor Assignments -->
        <div class="panel" style="padding: 20px; display: flex; flex-direction: column; justify-content: space-between;">
            <div>
                <div style="display: flex; justify-content: space-between; align-items: start; margin-bottom: 10px;">
                    <span class="sim-badge">Tutor Cohorts</span>
                    <span style="font-size: 20px;">👥</span>
                </div>
                <h3 style="font-size: 18px; font-weight: 700; color: #07366f; margin-bottom: 8px;">Group Creation &amp; Tutor Assignments</h3>
                <p style="font-size: 13px; color: var(--gta-muted); line-height: 1.5; margin-bottom: 16px;">
                    Form workshop training cohorts, appoint lead GTA tutors, record operational re-assignment reasons, and allocate or re-assign apprentices with live cohort roster views.
                </p>
            </div>
            <div>
                <a href="{{ route('admin.groups.index') }}" class="gta-btn gta-btn-secondary" style="width: 100%; text-align: center; display: block; text-decoration: none; font-size: 12px; font-weight: 700;">
                    Manage Groups &amp; Tutor Assignments &rarr;
                </a>
            </div>
        </div>

        <!-- 3. Careers -->
        <div class="panel" style="padding: 20px; display: flex; flex-direction: column; justify-content: space-between;">
            <div>
                <div style="display: flex; justify-content: space-between; align-items: start; margin-bottom: 10px;">
                    <span class="sim-badge">Careers Guidance</span>
                    <span style="font-size: 20px;">🧭</span>
                </div>
                <h3 style="font-size: 18px; font-weight: 700; color: #07366f; margin-bottom: 8px;">Careers &amp; Progression</h3>
                <p style="font-size: 13px; color: var(--gta-muted); line-height: 1.5; margin-bottom: 16px;">
                    Record confidential apprentice career discussions, track progression and specialist referrals, explore automotive career pathways, and maintain resource catalogues. Employers are strictly barred by privacy firewall.
                </p>
            </div>
            <div>
                <a href="{{ route('careers.index') }}" class="gta-btn gta-btn-secondary" style="width: 100%; text-align: center; display: block; text-decoration: none; font-size: 12px; font-weight: 700;">
                    Open Careers Area &rarr;
                </a>
            </div>
        </div>

        <!-- 4. Staff Development -->
        <div class="panel" style="padding: 20px; display: flex; flex-direction: column; justify-content: space-between;">
            <div>
                <div style="display: flex; justify-content: space-between; align-items: start; margin-bottom: 10px;">
                    <span class="sim-badge">Quality &amp; CPD</span>
                    <span style="font-size: 20px;">📈</span>
                </div>
                <h3 style="font-size: 18px; font-weight: 700; color: #07366f; margin-bottom: 8px;">Staff Development</h3>
                <p style="font-size: 13px; color: var(--gta-muted); line-height: 1.5; margin-bottom: 16px;">
                    Log continuous professional development (CPD), record teaching and workshop observations, conduct 1-to-1 appraisals, assign SMART development targets, and record private management notes.
                </p>
            </div>
            <div>
                <a href="{{ route('staff-development.index') }}" class="gta-btn gta-btn-secondary" style="width: 100%; text-align: center; display: block; text-decoration: none; font-size: 12px; font-weight: 700;">
                    Open Staff Development &rarr;
                </a>
            </div>
        </div>

        <!-- 5. General Reports Area -->
        <div class="panel" style="padding: 20px; display: flex; flex-direction: column; justify-content: space-between;">
            <div>
                <div style="display: flex; justify-content: space-between; align-items: start; margin-bottom: 10px;">
                    <span class="sim-badge">Executive Reporting</span>
                    <span style="font-size: 20px;">📊</span>
                </div>
                <h3 style="font-size: 18px; font-weight: 700; color: #07366f; margin-bottom: 8px;">General Reports Area</h3>
                <p style="font-size: 13px; color: var(--gta-muted); line-height: 1.5; margin-bottom: 16px;">
                    Reporting home dashboard featuring statutory attendance RAG breakdown (&ge;95% green, 90-95% amber, &lt;90% red), off-the-job pace tracking, 7-day marking SLA queue, and searchable reports directory.
                </p>
            </div>
            <div>
                <a href="{{ route('reports.index') }}" class="gta-btn gta-btn-secondary" style="width: 100%; text-align: center; display: block; text-decoration: none; font-size: 12px; font-weight: 700;">
                    Open Reporting Home &rarr;
                </a>
            </div>
        </div>

        <!-- 6. Help Guides -->
        <div class="panel" style="padding: 20px; display: flex; flex-direction: column; justify-content: space-between;">
            <div>
                <div style="display: flex; justify-content: space-between; align-items: start; margin-bottom: 10px;">
                    <span class="sim-badge">Knowledge Base</span>
                    <span style="font-size: 20px;">📚</span>
                </div>
                <h3 style="font-size: 18px; font-weight: 700; color: #07366f; margin-bottom: 8px;">Help Guides</h3>
                <p style="font-size: 13px; color: var(--gta-muted); line-height: 1.5; margin-bottom: 16px;">
                    Access {{ $helpCount }} role-scoped step-by-step workflow guides covering apprentice enrollment, marking SLAs, tripartite reviews, and compliance procedures, complete with browser speech audio playback.
                </p>
            </div>
            <div>
                <a href="{{ route('help.index') }}" class="gta-btn gta-btn-secondary" style="width: 100%; text-align: center; display: block; text-decoration: none; font-size: 12px; font-weight: 700;">
                    Browse Help Guides &rarr;
                </a>
            </div>
        </div>

        <!-- 7. Ofsted Inspection Overview -->
        <div class="panel" style="padding: 20px; display: flex; flex-direction: column; justify-content: space-between; border-left: 4px solid #075dcc;">
            <div>
                <div style="display: flex; justify-content: space-between; align-items: start; margin-bottom: 10px;">
                    <span class="sim-badge" style="background: #e8f1fb; color: #075dcc;">Statutory Audit &bull; Ofsted</span>
                    <span style="font-size: 20px;">🛡️</span>
                </div>
                <h3 style="font-size: 18px; font-weight: 700; color: #07366f; margin-bottom: 8px;">Inspection Overview</h3>
                <p style="font-size: 13px; color: var(--gta-muted); line-height: 1.5; margin-bottom: 16px;">
                    Aggregated inspection evidence across 6 key areas, dual-approved anonymous case studies bank, learner progression dossiers, and one-click printable audit-ready management packs.
                </p>
            </div>
            <div style="display: flex; gap: 10px;">
                <a href="{{ route('inspection.overview') }}" class="gta-btn gta-btn-secondary" style="flex: 1; text-align: center; text-decoration: none; font-size: 12px; font-weight: 700;">
                    Inspection Dossier &rarr;
                </a>
                <a href="{{ route('inspection.print') }}" target="_blank" class="gta-btn gta-btn-secondary" style="text-align: center; text-decoration: none; font-size: 12px;">
                    Print Pack
                </a>
            </div>
        </div>

    </div>
</x-portal-layout>


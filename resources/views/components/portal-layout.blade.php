<!DOCTYPE html>
<html lang="en-GB">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>{{ $title ?? 'GTA portal v1.2.8 | Apprentice Course Management' }}</title>

    <!-- Full Authentic Preview Stylesheet from GTA_Portal_v1.2.6_Complete_Developer_Pack/preview/index.html -->
    <link rel="stylesheet" href="{{ asset('css/gta-preview.css') }}">

    <style>
        /* Bridge styles to ensure Laravel blade route links inherit exact preview button styles */
        .sim-nav a {
            background: transparent;
            color: #e1e7ec;
            border: 1px solid #3d5061;
            border-radius: 8px;
            font-weight: 500;
            margin: 0;
            line-height: 1.5;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            padding: 10px 14px;
            font-size: 13px;
            cursor: pointer;
            transition: all 0.15s;
        }
        .sim-nav a:hover {
            background: #24394b;
            border-color: #7693a8;
            color: #fff;
        }
        .sim-nav a[aria-pressed="true"],
        .sim-nav a.active {
            background: #254c6a !important;
            border-color: #466e8b !important;
            color: #ffffff !important;
            font-weight: 700 !important;
        }
        .sim-nav-header {
            font-size: 11px;
            text-transform: uppercase;
            letter-spacing: 0.9px;
            color: #8da4be;
            font-weight: 700;
            padding: 12px 4px 4px;
            width: 100%;
        }

        /* Top header actions container */
        .sim-banner-actions {
            display: flex;
            align-items: center;
            gap: 12px;
            flex-wrap: wrap;
        }
        .btn-user-pill {
            display: flex;
            align-items: center;
            gap: 6px;
            font-size: 12px;
            color: #c1c9d9;
            background: rgba(255,255,255,0.06);
            padding: 6px 12px;
            border-radius: 6px;
            border: 1px solid rgba(255,255,255,0.12);
        }

        /* Responsive sidebar adaptations matching preview */
        @media screen and (min-width: 1100px) {
            #sim-app { display: flow-root; }
            .sim-nav {
                float: left;
                position: sticky;
                top: 16px;
                width: 224px;
                max-height: calc(100vh - 32px);
                overflow-y: auto;
                display: flex;
                flex-direction: column;
                align-items: stretch;
                padding: 20px 14px;
                margin: 0 0 20px;
                border-radius: 0 0 14px 0;
            }
            #sim-app > .sim-nav a {
                flex: none;
                text-align: left;
                justify-content: flex-start;
                width: 100%;
                min-width: 0;
                min-height: 44px;
                padding: 10px 12px;
            }
            #sim-app > :not(.sim-banner):not(.sim-nav) {
                margin-left: 248px !important;
                margin-right: 24px !important;
                width: calc(100% - 272px);
                max-width: 1500px;
                min-width: 0;
                box-sizing: border-box;
            }
        }

        @media screen and (max-width: 1099px) {
            .portal-nav-toggle { display: block; }
            .sim-nav[data-nav-closed="true"] { display: none !important; }
            .sim-nav {
                position: static;
                float: none;
                width: auto;
                max-height: 55vh;
                overflow-y: auto;
            }
            .sim-nav a { flex: 1 1 180px; }
        }

        /* Dark Mode overrides matching sample */
        body.portal-dark {
            background: #10151d !important;
            color: #f4f7fb !important;
        }
        body.portal-dark .panel,
        body.portal-dark .gta-card,
        body.portal-dark .rm-panel,
        body.portal-dark .accordion {
            background: #19212c !important;
            border-color: #354254 !important;
            color: #f4f7fb !important;
        }
        body.portal-dark .sim-review table th,
        body.portal-dark .gta-table th {
            background: #242c39 !important;
            color: #cbd5e1 !important;
        }
        body.portal-dark .sim-review table td,
        body.portal-dark .gta-table td {
            border-color: #354254 !important;
            color: #e7e9f1 !important;
        }
        body.portal-dark .gta-stat-card {
            background: #19212c !important;
            border-color: #354254 !important;
        }
        body.portal-dark input,
        body.portal-dark select,
        body.portal-dark textarea {
            background: #121722 !important;
            color: #f4f7fb !important;
            border-color: #354254 !important;
        }
    </style>
</head>
<body>
    <div id="sim-app" data-portal-theme="light">
        <!-- Top Banner matching sample -->
        <header class="sim-banner">
            <div class="approved-brand">
                <a href="{{ route('dashboard') }}" style="display: flex; align-items: center; gap: 14px; text-decoration: none; color: inherit;">
                    <img class="approved-logo" src="{{ asset('images/gta-logo.jpg') }}" alt="GTA logo">
                    <div>
                        <strong>GTA portal</strong>
                        <p>Doncaster, Rotherham &amp; District Motor Trades GTA Ltd &bull; Apprentice Course Management</p>
                    </div>
                </a>
            </div>

            <div class="sim-banner-actions">
                <div id="portal-release" class="portal-release">
                    <strong>v1.2.8</strong>
                    <span>Live Portal</span>
                    <small>Build 2026.11.10.3</small>
                </div>

                <div class="btn-user-pill">
                    <span>{{ Auth::user()->full_name }}</span>
                    <strong>({{ Auth::user()->role->label() }})</strong>
                </div>

                <!-- Dark Mode Switcher matching preview -->
                <button type="button" id="portal-appearance-toggle" role="switch" aria-checked="false" aria-label="Dark mode across the portal">
                    Dark mode
                </button>

                <!-- Mobile Navigation Toggle matching preview -->
                <button type="button" id="portal-nav-toggle" class="portal-nav-toggle" aria-controls="portal-navigation" aria-expanded="false">
                    Menu
                </button>

                <!-- Sign Out -->
                <form method="POST" action="{{ route('logout') }}" style="display: inline; margin: 0;">
                    @csrf
                    <button type="submit" class="back" style="background: #075dcc; border-color: #075dcc; color: white; margin: 0; padding: 7px 12px; font-size: 12px;">
                        Sign Out
                    </button>
                </form>
            </div>
        </header>

        <!-- Navigation matching sample's sim-nav -->
        <nav id="portal-navigation" class="sim-nav" aria-label="Portal navigation" data-nav-closed="true">
            <span class="sim-nav-header">Core Navigation</span>
            <a href="{{ route('dashboard') }}" class="{{ request()->routeIs('dashboard') ? 'active' : '' }}" aria-pressed="{{ request()->routeIs('dashboard') ? 'true' : 'false' }}">
                Apprentice profile
            </a>
            @if(Auth::user()->role !== \App\Enums\UserRole::Admin)
                <a href="{{ route('help.index') }}" class="{{ request()->routeIs('help.*') ? 'active' : '' }}" aria-pressed="{{ request()->routeIs('help.*') ? 'true' : 'false' }}">
                    Help &amp; how to
                </a>
            @endif

            @if(Auth::user()->isStaff())
                <a href="{{ route('attention.index') }}" class="{{ request()->routeIs('attention.*') ? 'active' : '' }}" aria-pressed="{{ request()->routeIs('attention.*') ? 'true' : 'false' }}">
                    Needs attention
                </a>
            @endif

            @if(Auth::user()->role === \App\Enums\UserRole::Admin)
                <span class="sim-nav-header">Administration</span>
                <a href="{{ route('admin.index') }}" class="{{ request()->routeIs('admin.index') ? 'active' : '' }}" aria-pressed="{{ request()->routeIs('admin.index') ? 'true' : 'false' }}">
                    Admin Hub
                </a>
                <a href="{{ route('admin.apprentices.index') }}" class="{{ request()->routeIs('admin.apprentices.index') ? 'active' : '' }}" aria-pressed="{{ request()->routeIs('admin.apprentices.index') ? 'true' : 'false' }}">
                    Apprentices list
                </a>
                <a href="{{ route('admin.apprentices.create') }}" class="{{ request()->routeIs('admin.apprentices.create') ? 'active' : '' }}" aria-pressed="{{ request()->routeIs('admin.apprentices.create') ? 'true' : 'false' }}">
                    Add apprentice
                </a>
                <a href="{{ route('admin.groups.index') }}" class="{{ request()->routeIs('admin.groups.*') ? 'active' : '' }}" aria-pressed="{{ request()->routeIs('admin.groups.*') ? 'true' : 'false' }}">
                    Group creation &amp; tutors
                </a>
                <a href="{{ route('careers.index') }}" class="{{ request()->routeIs('careers.*') ? 'active' : '' }}" aria-pressed="{{ request()->routeIs('careers.*') ? 'true' : 'false' }}">
                    Careers
                </a>
                <a href="{{ route('staff-development.index') }}" class="{{ request()->routeIs('staff-development.*') ? 'active' : '' }}" aria-pressed="{{ request()->routeIs('staff-development.*') ? 'true' : 'false' }}">
                    Staff development
                </a>
                <a href="{{ route('reports.index') }}" class="{{ request()->routeIs('reports.*') ? 'active' : '' }}" aria-pressed="{{ request()->routeIs('reports.*') ? 'true' : 'false' }}">
                    A general reports area
                </a>
                <a href="{{ route('help.index') }}" class="{{ request()->routeIs('help.*') ? 'active' : '' }}" aria-pressed="{{ request()->routeIs('help.*') ? 'true' : 'false' }}">
                    Help guides
                </a>
                <a href="{{ route('inspection.overview') }}" class="{{ request()->routeIs('inspection.*') ? 'active' : '' }}" aria-pressed="{{ request()->routeIs('inspection.*') ? 'true' : 'false' }}">
                    Inspection Overview
                </a>
            @endif

            <span class="sim-nav-header">Training &amp; Curriculum</span>
            <a href="{{ route('tasks.index') }}" class="{{ request()->routeIs('tasks.*') ? 'active' : '' }}" aria-pressed="{{ request()->routeIs('tasks.*') ? 'true' : 'false' }}">
                Block plan
            </a>
            <a href="{{ route('action-plan.index') }}" class="{{ request()->routeIs('action-plan.*') ? 'active' : '' }}" aria-pressed="{{ request()->routeIs('action-plan.*') ? 'true' : 'false' }}">
                Action Plan
            </a>
            <a href="{{ route('english-maths.index') }}" class="{{ request()->routeIs('english-maths.*') ? 'active' : '' }}" aria-pressed="{{ request()->routeIs('english-maths.*') ? 'true' : 'false' }}">
                English &amp; Maths
            </a>

            @if(Auth::user()->isStaff())
                <a href="{{ route('marking.index') }}" class="{{ request()->routeIs('marking.*') ? 'active' : '' }}" aria-pressed="{{ request()->routeIs('marking.*') ? 'true' : 'false' }}">
                    Tutor marking
                </a>
            @endif

            <span class="sim-nav-header">Registers &amp; Reviews</span>
            <a href="{{ route('attendance.index') }}" class="{{ request()->routeIs('attendance.*') ? 'active' : '' }}" aria-pressed="{{ request()->routeIs('attendance.*') ? 'true' : 'false' }}">
                Attendance
            </a>
            <a href="{{ route('otj.index') }}" class="{{ request()->routeIs('otj.*') ? 'active' : '' }}" aria-pressed="{{ request()->routeIs('otj.*') ? 'true' : 'false' }}">
                Off-The-Job (OTJ)
            </a>
            <a href="{{ route('reviews.index') }}" class="{{ request()->routeIs('reviews.*') ? 'active' : '' }}" aria-pressed="{{ request()->routeIs('reviews.*') ? 'true' : 'false' }}">
                Review monitoring
            </a>
            <a href="{{ route('journey.show') }}" class="{{ request()->routeIs('journey.*') ? 'active' : '' }}" aria-pressed="{{ request()->routeIs('journey.*') ? 'true' : 'false' }}">
                Apprentice Journey
            </a>

            @if(Auth::user()->role !== \App\Enums\UserRole::Employer)
                <span class="sim-nav-header">Support &amp; Inclusion</span>
                @if(Auth::user()->role !== \App\Enums\UserRole::Admin)
                    <a href="{{ route('careers.index') }}" class="{{ request()->routeIs('careers.*') ? 'active' : '' }}" aria-pressed="{{ request()->routeIs('careers.*') ? 'true' : 'false' }}">
                        Careers
                    </a>
                @endif
                @if(Auth::user()->isStaff())
                    <a href="{{ route('support.index') }}" class="{{ request()->routeIs('support.*') ? 'active' : '' }}" aria-pressed="{{ request()->routeIs('support.*') ? 'true' : 'false' }}">
                        Support &amp; concerns
                    </a>
                @endif
            @endif

            @if(Auth::user()->isStaff())
                <span class="sim-nav-header">Quality &amp; Standards</span>
                @if(Auth::user()->role !== \App\Enums\UserRole::Admin)
                    <a href="{{ route('staff-development.index') }}" class="{{ request()->routeIs('staff-development.*') ? 'active' : '' }}" aria-pressed="{{ request()->routeIs('staff-development.*') ? 'true' : 'false' }}">
                        Staff Development
                    </a>
                    <a href="{{ route('reports.index') }}" class="{{ request()->routeIs('reports.*') ? 'active' : '' }}" aria-pressed="{{ request()->routeIs('reports.*') ? 'true' : 'false' }}">
                        Reporting home
                    </a>
                @endif
                <a href="{{ route('case-studies.index') }}" class="{{ request()->routeIs('case-studies.*') ? 'active' : '' }}" aria-pressed="{{ request()->routeIs('case-studies.*') ? 'true' : 'false' }}">
                    Case studies
                </a>
            @endif
        </nav>

        <!-- Main Content Area -->
        <main class="main" id="sim-main-content">
            @if(Auth::user()->role === \App\Enums\UserRole::Admin)
                <!-- Administrator Area Launcher (v1.2.8) -->
                <details class="role-launcher" style="margin-bottom: 20px; background: var(--portal-panel); border: 1px solid var(--sim-line); border-radius: 10px; padding: 12px 16px;">
                    <summary style="font-weight: 650; cursor: pointer; color: var(--sim-text);">Open an area (Administrator Launcher)</summary>
                    <nav class="role-area-grid" aria-label="Administrator areas" style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 12px; margin-top: 12px;">
                        <a href="{{ route('admin.apprentices.index') }}" style="text-decoration: none; color: inherit; padding: 14px; background: var(--sim-soft); border: 1px solid var(--sim-line); border-radius: 8px; font-weight: 600; font-size: 14px; display: block;">Apprentices</a>
                        <a href="{{ route('admin.groups.index') }}" style="text-decoration: none; color: inherit; padding: 14px; background: var(--sim-soft); border: 1px solid var(--sim-line); border-radius: 8px; font-weight: 600; font-size: 14px; display: block;">Groups &amp; sessions</a>
                        <a href="{{ route('reviews.index') }}" style="text-decoration: none; color: inherit; padding: 14px; background: var(--sim-soft); border: 1px solid var(--sim-line); border-radius: 8px; font-weight: 600; font-size: 14px; display: block;">Reviews</a>
                        <a href="{{ route('reports.index') }}" style="text-decoration: none; color: inherit; padding: 14px; background: var(--sim-soft); border: 1px solid var(--sim-line); border-radius: 8px; font-weight: 600; font-size: 14px; display: block;">Reports</a>
                        <a href="{{ route('attention.index') }}" style="text-decoration: none; color: inherit; padding: 14px; background: var(--sim-soft); border: 1px solid var(--sim-line); border-radius: 8px; font-weight: 600; font-size: 14px; display: block;">Support &amp; retention</a>
                        <a href="{{ route('careers.index') }}" style="text-decoration: none; color: inherit; padding: 14px; background: var(--sim-soft); border: 1px solid var(--sim-line); border-radius: 8px; font-weight: 600; font-size: 14px; display: block;">Careers</a>
                        <a href="{{ route('staff-development.index') }}" style="text-decoration: none; color: inherit; padding: 14px; background: var(--sim-soft); border: 1px solid var(--sim-line); border-radius: 8px; font-weight: 600; font-size: 14px; display: block;">Staff development</a>
                        <a href="{{ route('case-studies.index') }}" style="text-decoration: none; color: inherit; padding: 14px; background: var(--sim-soft); border: 1px solid var(--sim-line); border-radius: 8px; font-weight: 600; font-size: 14px; display: block;">Case studies</a>
                        <a href="{{ route('help.index') }}" style="text-decoration: none; color: inherit; padding: 14px; background: var(--sim-soft); border: 1px solid var(--sim-line); border-radius: 8px; font-weight: 600; font-size: 14px; display: block;">Help &amp; how to</a>
                    </nav>
                </details>
            @endif

            {{ $slot }}

            <!-- Shared In-Place Rating Guide (v1.2.8) -->
            <details class="portal-status-guide" id="portal-status-guide">
                <summary id="portal-status-guide-toggle">How ratings work</summary>
                <div id="portal-status-guide-content">
                    <p>Read the status wording as well as the colour. Percent complete and schedule status are different measures.</p>
                    <ul class="status-key">
                        <li>
                            <span class="status-word" data-status-tone="blue">Completed / Achieved</span>
                            <span>Only where completion or achievement is recorded. A blue chart segment can show the completed portion; the whole record may still be in progress.</span>
                        </li>
                        <li>
                            <span class="status-word" data-status-tone="green">On track / Up to date</span>
                            <span>Meets the relevant rule for the loaded scope.</span>
                        </li>
                        <li>
                            <span class="status-word" data-status-tone="amber">Needs attention / Due soon</span>
                            <span>Near a deadline or within the area’s amber band.</span>
                        </li>
                        <li>
                            <span class="status-word" data-status-tone="red">Overdue / Off track</span>
                            <span>Past a valid deadline or below the area’s red threshold.</span>
                        </li>
                        <li>
                            <span class="status-word" data-status-tone="unknown">Not rated</span>
                            <span>Information is missing, incomplete or insufficient to rate.</span>
                        </li>
                    </ul>
                    <h3 style="font-size: 16px; margin: 16px 0 8px;">Rules for each area</h3>
                    <p style="font-size: 13px; color: var(--portal-muted);">These are GTA operational rules for this portal, not national inspection grades. They describe only the permitted loaded records.</p>
                    <p style="font-size: 13px;"><strong>Attendance:</strong> on track at 90% or above; needs attention from 80% to below 90%; off track below 80%. Missing marks prevent a final rating.</p>
                    <p style="font-size: 13px;"><strong>Marking:</strong> current waiting work is overdue after 7 calendar days.</p>
                    <p style="font-size: 13px;"><strong>Reviews &amp; Actions:</strong> red after the recorded due date; amber when due within 14 calendar days; green when up to date.</p>
                    <p style="font-size: 13px;"><strong>Off-the-Job (OTJ):</strong> confirmed subtotal of logged hours; a complete agreed cumulative ledger and individual target are checked separately.</p>
                    <p style="font-size: 13px;"><strong>Progress:</strong> Completion percentages describe the loaded tasks or assigned topics. They do not establish schedule status or qualification achievement.</p>
                </div>
            </details>
        </main>
    </div>

    <!-- Theme & Mobile Navigation Runtime Scripts matching sample -->
    <script>
        // Synchronize Appearance (Light / Dark)
        const themeBtn = document.getElementById('portal-appearance-toggle');
        const appContainer = document.getElementById('sim-app');

        function applyPortalTheme(mode) {
            const isDark = (mode === 'dark');
            appContainer.dataset.portalTheme = isDark ? 'dark' : 'light';
            document.body.classList.toggle('portal-dark', isDark);
            themeBtn.setAttribute('aria-checked', String(isDark));
            themeBtn.textContent = isDark ? 'Light mode' : 'Dark mode';
            localStorage.setItem('gta_portal_theme', isDark ? 'dark' : 'light');
        }

        const savedMode = localStorage.getItem('gta_portal_theme') || (window.matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light');
        applyPortalTheme(savedMode);

        themeBtn.addEventListener('click', () => {
            const nextMode = (appContainer.dataset.portalTheme === 'dark') ? 'light' : 'dark';
            applyPortalTheme(nextMode);
        });

        // Mobile Nav Drawer Toggle
        const navToggle = document.getElementById('portal-nav-toggle');
        const navBar = document.getElementById('portal-navigation');

        if (navToggle && navBar) {
            navToggle.addEventListener('click', () => {
                const isClosed = navBar.getAttribute('data-nav-closed') === 'true';
                if (isClosed) {
                    navBar.removeAttribute('data-nav-closed');
                    navToggle.setAttribute('aria-expanded', 'true');
                } else {
                    navBar.setAttribute('data-nav-closed', 'true');
                    navToggle.setAttribute('aria-expanded', 'false');
                }
            });
        }

        // Rating Explained Modal/In-Place Focus (v1.2.8)
        document.addEventListener('click', (event) => {
            const link = event.target.closest?.('[data-rating-explained]');
            if (!link) return;
            event.preventDefault();
            const guide = document.getElementById('portal-status-guide');
            if (guide) {
                guide.open = true;
                const toggle = document.getElementById('portal-status-guide-toggle');
                if (toggle) toggle.focus();
                guide.scrollIntoView({ block: 'start', behavior: 'smooth' });
            }
        });
    </script>
</body>
</html>

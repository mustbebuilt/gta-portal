<!DOCTYPE html>
<html lang="en-GB" data-theme="light">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>{{ $title ?? 'GTA Portal v1.2.6 | Apprentice Course Management' }}</title>

    <script>
        // Apply saved theme immediately before render to avoid flash
        const savedTheme = localStorage.getItem('gta_theme') || (window.matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light');
        document.documentElement.setAttribute('data-theme', savedTheme);
    </script>

    <style>
        :root {
            --sim-blue: #7059d3;
            --sim-text: #202b3d;
            --sim-line: #dfe3ec;
            --sim-soft: #f3f1fc;
            --dark-blue: #07366f;
            --gta-charcoal: #10141d;
            --gta-header: #171c29;
            --gta-accent: #7059d3;
            --gta-steel-blue: #254c6a;
            --gta-bg: #f4f5f8;
            --gta-text: #202b3d;
            --gta-border: #dfe3ec;
            --gta-panel: #ffffff;
            --gta-muted: #64748b;
            --gta-sidebar-w: 250px;
            --blue-table-border: #dfe7f2;
            --blue-table-bg: #f7faff;
            --brag-red: #e52b45;
            --brag-amber: #d17400;
            --brag-green: #11823d;
            --brag-blue: #075dcc;
        }

        [data-theme="dark"] {
            --gta-charcoal: #0a0d14;
            --gta-header: #111622;
            --gta-accent: #9277f0;
            --gta-steel-blue: #4077a3;
            --gta-bg: #131722;
            --gta-text: #e7eaf2;
            --gta-border: #283245;
            --gta-panel: #1b2130;
            --gta-muted: #94a3b8;
        }

        * { box-sizing: border-box; margin: 0; padding: 0; }
        body { background: var(--gta-bg); color: var(--gta-text); font-family: Inter, -apple-system, BlinkMacSystemFont, "Segoe UI", Arial, sans-serif; min-height: 100vh; font-size: 13px; line-height: 1.5; transition: background 0.2s, color 0.2s; }
        
        :focus-visible { outline: 3px solid var(--gta-accent); outline-offset: 3px; }

        /* Top Header Banner matching preview */
        .gta-banner { background: var(--gta-charcoal); color: #fff; padding: 12px 24px; display: flex; align-items: center; justify-content: space-between; border-bottom: 1px solid rgba(255,255,255,0.08); position: sticky; top: 0; z-index: 50; }
        .gta-brand { display: flex; align-items: center; gap: 14px; text-decoration: none; color: inherit; }
        .gta-logo { width: 44px; height: 44px; background: #fff; border-radius: 8px; padding: 3px; object-fit: contain; }
        .gta-brand h1 { font-size: 16px; font-weight: 700; letter-spacing: -0.2px; }
        .gta-brand p { font-size: 11px; color: #a0aec0; }
        .gta-user-menu { display: flex; align-items: center; gap: 14px; font-size: 13px; }

        /* Buttons & Badges */
        .gta-btn { display: inline-flex; align-items: center; justify-content: center; gap: 6px; padding: 8px 16px; border-radius: 6px; font-weight: 600; font-size: 13px; cursor: pointer; text-decoration: none; transition: all 0.15s; border: 1px solid transparent; }
        .gta-btn-primary { background: var(--gta-accent); color: #fff; border-color: var(--gta-accent); }
        .gta-btn-primary:hover { opacity: 0.92; }
        .gta-btn-secondary { background: var(--gta-panel); color: var(--gta-steel-blue); border-color: var(--gta-border); }
        .gta-btn-secondary:hover { background: var(--gta-bg); }

        .btn-theme-toggle { background: rgba(255,255,255,0.08); border: 1px solid rgba(255,255,255,0.15); color: #fff; width: 34px; height: 34px; border-radius: 6px; cursor: pointer; display: grid; place-items: center; font-size: 14px; }
        .btn-theme-toggle:hover { background: rgba(255,255,255,0.18); }
        .btn-mobile-nav { display: none; background: transparent; border: none; color: #fff; font-size: 22px; cursor: pointer; padding: 4px; }

        /* Main Workspace Container */
        .gta-container { display: flex; min-height: calc(100vh - 68px); }

        /* Sidebar Navigation */
        .gta-sidebar { width: var(--gta-sidebar-w); background: var(--gta-header); padding: 16px 12px; display: flex; flex-direction: column; gap: 4px; flex-shrink: 0; max-height: calc(100vh - 68px); position: sticky; top: 68px; overflow-y: auto; }
        .gta-nav-item { display: flex; align-items: center; gap: 10px; padding: 9px 12px; color: #c8d0df; text-decoration: none; border-radius: 6px; font-size: 12.5px; font-weight: 500; transition: all 0.15s; }
        .gta-nav-item:hover { background: #2a3041; color: #fff; }
        .gta-nav-item.active { background: #b5a1f5; color: #201834; font-weight: 700; }
        .nav-section-title { font-size: 10px; text-transform: uppercase; letter-spacing: 0.8px; color: #64748b; font-weight: 700; padding: 12px 12px 4px; margin-top: 4px; }

        /* Content Area */
        .gta-content { flex: 1; padding: 24px 32px; max-width: 1450px; min-width: 0; }

        /* RAG Status Pills */
        .badge { display: inline-block; padding: 3px 8px; border-radius: 9999px; font-size: 11px; font-weight: 700; text-transform: uppercase; letter-spacing: 0.5px; }
        .badge-green { background: #dcfce7; color: #15803d; }
        .badge-amber { background: #fef3c7; color: #b45309; }
        .badge-red { background: #fee2e2; color: #b91c1c; }
        .badge-blue { background: #dbeafe; color: #1d4ed8; }
        .badge-purple { background: #ede9fe; color: #6d28d9; }
        .badge-gray { background: #f1f5f9; color: #475569; }

        [data-theme="dark"] .badge-green { background: #143823; color: #86efac; }
        [data-theme="dark"] .badge-amber { background: #3d240d; color: #fde68a; }
        [data-theme="dark"] .badge-red { background: #401418; color: #fca5a5; }
        [data-theme="dark"] .badge-blue { background: #172d54; color: #93c5fd; }
        [data-theme="dark"] .badge-purple { background: #2e1065; color: #c4b5fd; }
        [data-theme="dark"] .badge-gray { background: #1e293b; color: #94a3b8; }

        /* Stat Grid */
        .gta-stat-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 14px; margin-bottom: 20px; }
        .gta-stat-card { background: var(--gta-panel); border: 1px solid var(--gta-border); border-radius: 8px; padding: 16px; }
        .gta-stat-card small { font-size: 11px; color: var(--gta-muted); font-weight: 600; text-transform: uppercase; letter-spacing: 0.5px; }
        .gta-stat-card strong { display: block; font-size: 24px; font-weight: 700; color: var(--gta-steel-blue); margin-top: 4px; }
        [data-theme="dark"] .gta-stat-card strong { color: var(--gta-accent); }

        /* Cards & Panels */
        .gta-card { background: var(--gta-panel); border: 1px solid var(--gta-border); border-radius: 10px; padding: 20px; margin-bottom: 20px; box-shadow: 0 1px 2px rgba(20,40,80,.04); }
        .gta-table { width: 100%; border-collapse: collapse; font-size: 13px; }
        .gta-table th, .gta-table td { padding: 10px 12px; text-align: left; border-bottom: 1px solid var(--gta-border); }
        .gta-table th { background: #f4f2fa; font-weight: 700; color: #475368; font-size: 12px; }
        [data-theme="dark"] .gta-table th { background: #1e2533; color: #cbd5e1; }

        .btn-logout { background: transparent; border: 1px solid rgba(255,255,255,0.2); color: #e2e8f0; padding: 6px 12px; border-radius: 6px; cursor: pointer; font-size: 12px; }
        .btn-logout:hover { background: rgba(255,255,255,0.1); }

        /* Responsive Breakpoints */
        @media (max-width: 1099px) {
            .btn-mobile-nav { display: block; }
            .gta-sidebar { position: fixed; left: 0; top: 68px; bottom: 0; z-index: 40; transform: translateX(-100%); width: 260px; box-shadow: 4px 0 16px rgba(0,0,0,0.3); }
            .gta-sidebar.open { transform: translateX(0); }
            .gta-content { padding: 18px; }
            .gta-brand h1 { font-size: 14px; }
            .gta-brand p { display: none; }
        }

        @media print {
            .gta-banner, .gta-sidebar, .no-print { display: none !important; }
            .gta-container { min-height: auto; }
            .gta-content { margin: 0 !important; width: 100% !important; padding: 0 !important; }
            body { background: #fff !important; color: #000 !important; font-size: 10pt; }
        }
    </style>
</head>
<body>
    <header class="gta-banner">
        <div style="display: flex; align-items: center; gap: 12px;">
            <button class="btn-mobile-nav" id="mobileNavToggle" aria-label="Toggle navigation menu">&#9776;</button>
            <a href="{{ route('dashboard') }}" class="gta-brand">
                <img src="{{ asset('images/gta-logo.jpg') }}" alt="GTA Logo" class="gta-logo">
                <div>
                    <h1>GTA Apprentice Portal</h1>
                    <p>v1.2.6 &bull; build 2026.11.08.6 &bull; Doncaster, Rotherham &amp; District Motor Trades GTA</p>
                </div>
            </a>
        </div>
        <div class="gta-user-menu">
            <!-- Light/Dark Mode Switch -->
            <button class="btn-theme-toggle" id="themeToggle" aria-label="Toggle light or dark theme" title="Toggle theme">
                <span id="themeIcon">&#9728;</span>
            </button>

            <span style="display: flex; align-items: center; gap: 6px;">
                <span style="max-width: 140px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap;">{{ Auth::user()->full_name }}</span>
                (<strong>{{ Auth::user()->role->label() }}</strong>)
            </span>

            <form method="POST" action="{{ route('logout') }}" style="display: inline;">
                @csrf
                <button type="submit" class="btn-logout">Sign Out</button>
            </form>
        </div>
    </header>

    <div class="gta-container">
        <aside class="gta-sidebar" id="gtaSidebar">
            <span class="nav-section-title">Core Navigation</span>
            <a href="{{ route('dashboard') }}" class="gta-nav-item {{ request()->routeIs('dashboard') ? 'active' : '' }}">
                <span>&#128202;</span> Dashboard
            </a>
            <a href="{{ route('help.index') }}" class="gta-nav-item {{ request()->routeIs('help.*') ? 'active' : '' }}">
                <span>&#10067;</span> Help &amp; How To
            </a>

            @if(Auth::user()->role === \App\Enums\UserRole::Admin)
                <span class="nav-section-title">Apprenticeship Admin</span>
                <a href="{{ route('admin.apprentices.index') }}" class="gta-nav-item {{ request()->routeIs('admin.apprentices.index') ? 'active' : '' }}">
                    <span>&#128101;</span> View Apprentices
                </a>
                <a href="{{ route('admin.apprentices.create') }}" class="gta-nav-item {{ request()->routeIs('admin.apprentices.create') ? 'active' : '' }}">
                    <span>&#10133;</span> Add Apprentice
                </a>
                <a href="{{ route('admin.groups.index') }}" class="gta-nav-item {{ request()->routeIs('admin.groups.*') ? 'active' : '' }}">
                    <span>&#127979;</span> Manage Tutor Groups
                </a>
            @endif

            <span class="nav-section-title">Curriculum &amp; Training</span>
            <a href="{{ route('tasks.index') }}" class="gta-nav-item {{ request()->routeIs('tasks.*') ? 'active' : '' }}">
                <span>&#128218;</span> 10-Block Curriculum
            </a>

            @if(Auth::user()->isStaff())
                <a href="{{ route('marking.index') }}" class="gta-nav-item {{ request()->routeIs('marking.*') ? 'active' : '' }}">
                    <span>&#9998;</span> Marking SLA Queue
                </a>
            @endif

            <span class="nav-section-title">Registers &amp; Progress</span>
            <a href="{{ route('attendance.index') }}" class="gta-nav-item {{ request()->routeIs('attendance.*') ? 'active' : '' }}">
                <span>&#128197;</span> Attendance Registers
            </a>
            <a href="{{ route('otj.index') }}" class="gta-nav-item {{ request()->routeIs('otj.*') ? 'active' : '' }}">
                <span>&#9201;</span> Off-The-Job (OTJ)
            </a>
            <a href="{{ route('reviews.index') }}" class="gta-nav-item {{ request()->routeIs('reviews.*') ? 'active' : '' }}">
                <span>&#9997;</span> Progress Reviews
            </a>
            <a href="{{ route('journey.show') }}" class="gta-nav-item {{ request()->routeIs('journey.*') ? 'active' : '' }}">
                <span>&#128506;</span> Apprentice Journey
            </a>

            @if(Auth::user()->role !== \App\Enums\UserRole::Employer)
                <span class="nav-section-title">Support &amp; Guidance</span>
                <a href="{{ route('careers.index') }}" class="gta-nav-item {{ request()->routeIs('careers.*') ? 'active' : '' }}">
                    <span>&#127937;</span> Careers &amp; Guidance
                </a>
                @if(Auth::user()->isStaff())
                    <a href="{{ route('support.index') }}" class="gta-nav-item {{ request()->routeIs('support.*') ? 'active' : '' }}">
                        <span>&#129309;</span> ALS Support Plans
                    </a>
                @endif
            @endif

            @if(Auth::user()->isStaff())
                <span class="nav-section-title">Quality &amp; Leadership</span>
                <a href="{{ route('staff-development.index') }}" class="gta-nav-item {{ request()->routeIs('staff-development.*') ? 'active' : '' }}">
                    <span>&#128188;</span> Staff Development
                </a>
                <a href="{{ route('reports.index') }}" class="gta-nav-item {{ request()->routeIs('reports.*') ? 'active' : '' }}">
                    <span>&#128200;</span> Reporting Home
                </a>
                <a href="{{ route('case-studies.index') }}" class="gta-nav-item {{ request()->routeIs('case-studies.*') ? 'active' : '' }}">
                    <span>&#128214;</span> Case Studies
                </a>
            @endif

            @if(Auth::user()->role === \App\Enums\UserRole::Admin)
                <span class="nav-section-title">Auditor &amp; Ofsted</span>
                <a href="{{ route('inspection.overview') }}" class="gta-nav-item {{ request()->routeIs('inspection.*') ? 'active' : '' }}">
                    <span>&#128269;</span> Inspection Overview
                </a>
            @endif
        </aside>

        <main class="gta-content">
            {{ $slot }}
        </main>
    </div>

    <script>
        // Theme Switcher Logic
        const themeToggle = document.getElementById('themeToggle');
        const themeIcon = document.getElementById('themeIcon');

        function updateThemeIcon(theme) {
            themeIcon.textContent = theme === 'dark' ? '🌙' : '☀️';
        }

        updateThemeIcon(document.documentElement.getAttribute('data-theme'));

        themeToggle.addEventListener('click', () => {
            const current = document.documentElement.getAttribute('data-theme');
            const next = current === 'dark' ? 'light' : 'dark';
            document.documentElement.setAttribute('data-theme', next);
            localStorage.setItem('gta_theme', next);
            updateThemeIcon(next);
        });

        // Mobile Sidebar Toggle Logic
        const mobileToggle = document.getElementById('mobileNavToggle');
        const sidebar = document.getElementById('gtaSidebar');

        if (mobileToggle && sidebar) {
            mobileToggle.addEventListener('click', () => {
                sidebar.classList.toggle('open');
            });

            // Close sidebar when clicking outside on mobile
            document.addEventListener('click', (e) => {
                if (!sidebar.contains(e.target) && !mobileToggle.contains(e.target) && sidebar.classList.contains('open')) {
                    sidebar.classList.remove('open');
                }
            });
        }
    </script>
</body>
</html>

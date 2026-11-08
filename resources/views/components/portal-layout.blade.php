<!DOCTYPE html>
<html lang="en" data-theme="light">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>{{ $title ?? 'GTA Portal | Apprentice & Course Management' }}</title>

    <script>
        // Apply saved theme immediately before render to avoid flash
        const savedTheme = localStorage.getItem('gta_theme') || (window.matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light');
        document.documentElement.setAttribute('data-theme', savedTheme);
    </script>

    <style>
        :root {
            --gta-charcoal: #10141d;
            --gta-header: #171c29;
            --gta-accent: #7059d3;
            --gta-steel-blue: #254c6a;
            --gta-bg: #f4f5f8;
            --gta-text: #202b3d;
            --gta-border: #dfe3ec;
            --gta-panel: #ffffff;
            --gta-muted: #64748b;
            --gta-sidebar-w: 240px;
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
        body { background: var(--gta-bg); color: var(--gta-text); font-family: Inter, -apple-system, BlinkMacSystemFont, "Segoe UI", Arial, sans-serif; min-height: 100vh; transition: background 0.2s, color 0.2s; }
        
        :focus-visible { outline: 3px solid var(--gta-accent); outline-offset: 3px; }

        /* Top Header Banner */
        .gta-banner { background: var(--gta-charcoal); color: #fff; padding: 12px 24px; display: flex; align-items: center; justify-content: space-between; border-bottom: 1px solid rgba(255,255,255,0.08); position: sticky; top: 0; z-index: 50; }
        .gta-brand { display: flex; align-items: center; gap: 14px; text-decoration: none; color: inherit; }
        .gta-logo { width: 42px; height: 42px; background: #fff; border-radius: 6px; padding: 2px; object-fit: contain; }
        .gta-brand h1 { font-size: 16px; font-weight: 700; }
        .gta-brand p { font-size: 11px; color: #a0aec0; }
        .gta-user-menu { display: flex; align-items: center; gap: 14px; font-size: 13px; }

        /* Controls: Theme & Hamburger */
        .btn-theme-toggle { background: rgba(255,255,255,0.08); border: 1px solid rgba(255,255,255,0.15); color: #fff; width: 34px; height: 34px; border-radius: 6px; cursor: pointer; display: grid; place-items: center; font-size: 14px; transition: background 0.15s; }
        .btn-theme-toggle:hover { background: rgba(255,255,255,0.18); }
        .btn-mobile-nav { display: none; background: transparent; border: none; color: #fff; font-size: 22px; cursor: pointer; padding: 4px; }

        /* Main Workspace Container */
        .gta-container { display: flex; min-height: calc(100vh - 66px); }

        /* Sidebar Navigation */
        .gta-sidebar { width: var(--gta-sidebar-w); background: var(--gta-header); padding: 20px 12px; display: flex; flex-direction: column; gap: 6px; flex-shrink: 0; transition: transform 0.25s ease-in-out; }
        .gta-nav-item { display: flex; align-items: center; gap: 10px; padding: 10px 14px; color: #cbd5e1; text-decoration: none; border-radius: 6px; font-size: 13px; font-weight: 500; transition: all 0.15s; }
        .gta-nav-item:hover { background: rgba(255,255,255,0.08); color: #fff; }
        .gta-nav-item.active { background: var(--gta-accent); color: #fff; font-weight: 600; }
        .nav-section-title { font-size: 10px; text-transform: uppercase; letter-spacing: 1px; color: #64748b; font-weight: 700; padding: 12px 14px 4px; margin-top: 6px; }

        /* Content Area */
        .gta-content { flex: 1; padding: 28px; max-width: 1400px; }

        /* RAG Status Pills */
        .badge { display: inline-block; padding: 4px 10px; border-radius: 9999px; font-size: 11px; font-weight: 700; text-transform: uppercase; letter-spacing: 0.5px; }
        .badge-green { background: #dcfce7; color: #15803d; }
        .badge-amber { background: #fef3c7; color: #b45309; }
        .badge-red { background: #fee2e2; color: #b91c1c; }
        .badge-blue { background: #dbeafe; color: #1d4ed8; }

        [data-theme="dark"] .badge-green { background: #143823; color: #86efac; }
        [data-theme="dark"] .badge-amber { background: #3d240d; color: #fde68a; }
        [data-theme="dark"] .badge-red { background: #401418; color: #fca5a5; }
        [data-theme="dark"] .badge-blue { background: #172d54; color: #93c5fd; }

        /* Stat Grid */
        .gta-stat-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); gap: 16px; margin-bottom: 24px; }
        .gta-stat-card { background: var(--gta-panel); border: 1px solid var(--gta-border); border-radius: 8px; padding: 18px; }
        .gta-stat-card small { font-size: 11px; color: var(--gta-muted); font-weight: 600; text-transform: uppercase; letter-spacing: 0.5px; }
        .gta-stat-card strong { display: block; font-size: 26px; font-weight: 700; color: var(--gta-steel-blue); margin-top: 4px; }
        [data-theme="dark"] .gta-stat-card strong { color: var(--gta-accent); }

        /* Cards & Panels */
        .gta-card { background: var(--gta-panel); border: 1px solid var(--gta-border); border-radius: 10px; padding: 22px; margin-bottom: 20px; }
        .gta-card header { border-bottom: 1px solid var(--gta-border); padding-bottom: 14px; margin-bottom: 18px; display: flex; justify-content: space-between; align-items: center; }
        .gta-table { width: 100%; border-collapse: collapse; font-size: 13px; }
        .gta-table th, .gta-table td { padding: 12px; text-align: left; border-bottom: 1px solid var(--gta-border); }
        .gta-table th { background: rgba(0,0,0,0.02); font-weight: 600; color: var(--gta-muted); }
        [data-theme="dark"] .gta-table th { background: rgba(255,255,255,0.02); }

        .btn-logout { background: transparent; border: 1px solid rgba(255,255,255,0.2); color: #e2e8f0; padding: 6px 12px; border-radius: 6px; cursor: pointer; font-size: 12px; }
        .btn-logout:hover { background: rgba(255,255,255,0.1); }

        /* Responsive Breakpoints */
        @media (max-width: 1099px) {
            .btn-mobile-nav { display: block; }
            .gta-sidebar { position: fixed; left: 0; top: 66px; bottom: 0; z-index: 40; transform: translateX(-100%); width: 260px; box-shadow: 4px 0 16px rgba(0,0,0,0.3); }
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
                    <h1>GTA Course &amp; Apprentice Portal</h1>
                    <p>Build 2026.11.08.6 &bull; Doncaster, Rotherham &amp; District Motor Trades GTA</p>
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

            <a href="{{ route('tasks.index') }}" class="gta-nav-item {{ request()->routeIs('tasks.*') ? 'active' : '' }}">
                <span>&#128218;</span> 10-Block Curriculum
            </a>

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

            @if(Auth::user()->isStaff())
                <span class="nav-section-title">Quality &amp; Staff</span>
                <a href="{{ route('marking.index') }}" class="gta-nav-item {{ request()->routeIs('marking.*') ? 'active' : '' }}">
                    <span>&#9998;</span> Marking SLA Queue
                </a>
                <a href="{{ route('case-studies.index') }}" class="gta-nav-item {{ request()->routeIs('case-studies.*') ? 'active' : '' }}">
                    <span>&#128214;</span> Case Studies
                </a>
                <a href="{{ route('support.index') }}" class="gta-nav-item {{ request()->routeIs('support.*') ? 'active' : '' }}">
                    <span>&#129309;</span> ALS Support Plans
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

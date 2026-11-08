<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <meta name="theme-color" content="#10141d">
    <title>GTA | Your Next Step</title>
    <style>
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body { background: #10141d; color: #f5f5fc; font-family: Inter, -apple-system, BlinkMacSystemFont, "Segoe UI", Arial, sans-serif; min-height: 100vh; display: flex; flex-direction: column; }
        a { color: inherit; text-decoration: none; }
        header { padding: 20px 4%; display: flex; align-items: center; justify-content: space-between; border-bottom: 1px solid rgba(255,255,255,0.1); background: #10141d; }
        .identity { display: flex; align-items: center; gap: 14px; }
        .logo { width: 44px; height: 44px; border-radius: 6px; background: white; padding: 3px; object-fit: contain; }
        .identity strong { display: block; font-size: 14px; font-weight: 700; letter-spacing: 0.3px; }
        .identity small { display: block; font-size: 10px; letter-spacing: 1.5px; color: #a3adc0; margin-top: 4px; }
        .toplinks { display: flex; align-items: center; gap: 20px; }
        .btn-signin-top { background: #254c6a; color: white; padding: 8px 18px; border-radius: 6px; font-size: 13px; font-weight: 600; }
        .btn-signin-top:hover { background: #193b55; }
        
        .main { flex: 1; display: grid; grid-template-columns: minmax(0, 1.6fr) minmax(360px, 1fr); min-height: calc(100vh - 85px); }
        .hero { position: relative; overflow: hidden; display: flex; flex-direction: column; justify-content: space-between; background: #111723; padding: 50px 7% 30px; }
        .hero-image { position: absolute; inset: 0; width: 100%; height: 100%; object-fit: cover; object-position: center bottom; opacity: 0.35; z-index: 0; }
        .hero-copy { position: relative; z-index: 1; max-width: 620px; }
        .kicker { font-size: 11px; text-transform: uppercase; letter-spacing: 2px; color: #9ec3da; margin-bottom: 18px; }
        h1 { font-size: clamp(34px, 4vw, 56px); font-weight: 700; line-height: 1.1; margin-bottom: 20px; }
        h1 span { color: #9ec3da; }
        .hero-copy p { color: #cbd5e1; font-size: 15px; line-height: 1.6; }
        .hero-bottom { position: relative; z-index: 1; border-top: 1px solid rgba(255,255,255,0.15); padding-top: 24px; display: grid; grid-template-columns: repeat(3, 1fr); gap: 20px; }
        .hero-bottom strong { display: block; font-size: 12px; color: #fff; margin-bottom: 6px; }
        .hero-bottom p { font-size: 11px; color: #94a3b8; line-height: 1.5; }

        .entry { background: #f4f5f8; color: #17202e; display: flex; flex-direction: column; justify-content: center; padding: 48px clamp(28px, 4vw, 64px); }
        .card { width: 100%; max-width: 380px; margin: auto; }
        .entry-label { color: #254c6a; letter-spacing: 2px; font-size: 10px; font-weight: 700; margin-bottom: 16px; }
        .entry h2 { font-size: 30px; font-weight: 700; margin-bottom: 10px; color: #0f172a; }
        .intro { color: #64748b; font-size: 13px; line-height: 1.6; margin-bottom: 24px; }
        .access-info { background: white; border: 1px solid #e2e8f0; padding: 16px; border-radius: 8px; margin-bottom: 24px; }
        .access-info strong { font-size: 13px; color: #0f172a; display: block; margin-bottom: 4px; }
        .access-info p { font-size: 11px; color: #64748b; line-height: 1.5; }
        .primary { width: 100%; background: #254c6a; color: white; display: block; text-align: center; padding: 14px 20px; border-radius: 6px; font-size: 14px; font-weight: 600; transition: background 0.15s; }
        .primary:hover { background: #193b55; }
        .login-note { color: #64748b; font-size: 11px; text-align: center; margin-top: 12px; }

        footer { background: #0c1017; color: #64748b; font-size: 11px; padding: 18px 4%; display: flex; justify-content: space-between; border-top: 1px solid rgba(255,255,255,0.08); }
        @media (max-width: 860px) {
            .main { grid-template-columns: 1fr; }
            .hero { min-height: 380px; }
        }
    </style>
</head>
<body>
    <header>
        <a class="identity" href="{{ route('welcome') }}">
            <img class="logo" src="{{ asset('images/gta-logo.jpg') }}" alt="GTA Logo">
            <div>
                <strong>GTA Apprenticeship Portal</strong>
                <small>YOUR AMBITION. YOUR PROGRESS.</small>
            </div>
        </a>
        <div class="toplinks">
            <a href="{{ route('login') }}" class="btn-signin-top">Sign in</a>
        </div>
    </header>

    <main class="main">
        <section class="hero">
            <img class="hero-image" src="{{ asset('images/vehicle-hero.png') }}" alt="Automotive and logistics training vehicles">
            <div class="hero-copy">
                <div class="kicker">Doncaster &amp; Sheffield Motor Trades GTA</div>
                <h1>Build skills.<br><span>Challenge limits.</span></h1>
                <p>Industry-standard automotive and logistics apprenticeship management for learners, employers, and trainers.</p>
            </div>
            <div class="hero-bottom">
                <div>
                    <strong>Your Learning</strong>
                    <p>Track your 10 training blocks and 50 core practical milestones.</p>
                </div>
                <div>
                    <strong>Your Progress</strong>
                    <p>Keep your 70-day review cycles and OTJ hours fully in view.</p>
                </div>
                <div>
                    <strong>Your Support</strong>
                    <p>Dedicated mentorship and confidential learning assistance.</p>
                </div>
            </div>
        </section>

        <section class="entry">
            <div class="card">
                <div class="entry-label">SECURE LEARNER &amp; STAFF PORTAL</div>
                <h2>Welcome to GTA</h2>
                <p class="intro">Sign in to manage your apprenticeship coursework, review submissions, and track progress.</p>

                <div class="access-info">
                    <strong>Use your verified GTA account</strong>
                    <p>For apprentices, workshop mentors, GTA tutors, and quality auditors.</p>
                </div>

                <a href="{{ route('login') }}" class="primary">Continue to Sign In &rarr;</a>
                <p class="login-note">Powered by Laravel 11 &bull; Database Schema v1.2.6</p>
            </div>
        </section>
    </main>

    <footer>
        <span>&copy; {{ date('Y') }} Doncaster, Rotherham and District Motor Trades GTA Ltd.</span>
        <span>BUILDING SKILLS SINCE 1972</span>
    </footer>
</body>
</html>

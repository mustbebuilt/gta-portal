<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title>GTA | Sign In</title>
    <style>
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body { background: #10141d; color: #f5f5fc; font-family: Inter, -apple-system, BlinkMacSystemFont, "Segoe UI", Arial, sans-serif; min-height: 100vh; display: flex; flex-direction: column; }
        a { color: inherit; text-decoration: none; }
        header { padding: 20px 4%; display: flex; align-items: center; justify-content: space-between; border-bottom: 1px solid rgba(255,255,255,0.1); background: #10141d; }
        .identity { display: flex; align-items: center; gap: 14px; }
        .logo { width: 44px; height: 44px; border-radius: 6px; background: white; padding: 3px; object-fit: contain; }
        .identity strong { display: block; font-size: 14px; font-weight: 700; letter-spacing: 0.3px; }
        .identity small { display: block; font-size: 10px; letter-spacing: 1.5px; color: #a3adc0; margin-top: 4px; }

        .main { flex: 1; display: grid; grid-template-columns: minmax(0, 1.4fr) minmax(380px, 1fr); min-height: calc(100vh - 85px); }
        .hero { position: relative; overflow: hidden; display: flex; flex-direction: column; justify-content: space-between; background: #111723; padding: 50px 7% 30px; }
        .hero-image { position: absolute; inset: 0; width: 100%; height: 100%; object-fit: cover; object-position: center bottom; opacity: 0.35; z-index: 0; }
        .hero-copy { position: relative; z-index: 1; max-width: 580px; }
        .kicker { font-size: 11px; text-transform: uppercase; letter-spacing: 2px; color: #9ec3da; margin-bottom: 18px; }
        h1 { font-size: clamp(32px, 3.8vw, 50px); font-weight: 700; line-height: 1.1; margin-bottom: 20px; }
        h1 span { color: #9ec3da; }

        .entry { background: #f4f5f8; color: #17202e; display: flex; flex-direction: column; justify-content: center; padding: 40px clamp(28px, 4vw, 54px); }
        .card { width: 100%; max-width: 420px; margin: auto; }
        .back-link { font-size: 12px; color: #64748b; margin-bottom: 16px; display: inline-block; font-weight: 500; }
        .back-link:hover { color: #254c6a; }
        .entry-label { color: #254c6a; letter-spacing: 2px; font-size: 10px; font-weight: 700; margin-bottom: 12px; }
        .entry h2 { font-size: 28px; font-weight: 700; margin-bottom: 8px; color: #0f172a; }
        .intro { color: #64748b; font-size: 13px; line-height: 1.5; margin-bottom: 22px; }

        .form-group { margin-bottom: 16px; }
        .form-group label { display: block; font-size: 12px; font-weight: 600; color: #334155; margin-bottom: 6px; }
        .form-control { width: 100%; padding: 11px 14px; border: 1px solid #cbd5e1; border-radius: 6px; font-size: 13px; font-family: inherit; background: #fff; }
        .form-control:focus { outline: none; border-color: #254c6a; box-shadow: 0 0 0 3px rgba(37, 76, 106, 0.15); }
        .form-options { display: flex; justify-content: space-between; align-items: center; font-size: 12px; margin-bottom: 20px; }
        .checkbox-label { display: flex; align-items: center; gap: 8px; cursor: pointer; color: #475569; }

        .primary { width: 100%; background: #254c6a; color: white; border: none; padding: 13px; border-radius: 6px; font-size: 14px; font-weight: 600; cursor: pointer; transition: background 0.15s; }
        .primary:hover { background: #193b55; }

        .error-alert { background: #fee2e2; border: 1px solid #f87171; color: #991b1b; padding: 12px; border-radius: 6px; font-size: 13px; margin-bottom: 18px; }

        /* Quick-fill testing buttons */
        .quick-fill { margin-top: 24px; padding-top: 18px; border-top: 1px solid #e2e8f0; }
        .quick-fill small { display: block; font-size: 11px; font-weight: 600; text-transform: uppercase; letter-spacing: 0.5px; color: #64748b; margin-bottom: 8px; }
        .quick-pills { display: flex; flex-wrap: wrap; gap: 6px; }
        .quick-pill { background: #e2e8f0; border: none; padding: 5px 10px; border-radius: 4px; font-size: 11px; font-weight: 600; color: #334155; cursor: pointer; }
        .quick-pill:hover { background: #cbd5e1; color: #0f172a; }

        footer { background: #0c1017; color: #64748b; font-size: 11px; padding: 18px 4%; display: flex; justify-content: space-between; border-top: 1px solid rgba(255,255,255,0.08); }
        @media (max-width: 860px) {
            .main { grid-template-columns: 1fr; }
            .hero { display: none; }
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
    </header>

    <main class="main">
        <section class="hero">
            <img class="hero-image" src="{{ asset('images/vehicle-hero.png') }}" alt="Vehicles">
            <div class="hero-copy">
                <div class="kicker">Verified Portal Sign-In</div>
                <h1>Welcome back.<br><span>Continue learning.</span></h1>
            </div>
        </section>

        <section class="entry">
            <div class="card">
                <a href="{{ route('welcome') }}" class="back-link">&larr; Back to welcome</a>
                <div class="entry-label">SECURE ACCESS</div>
                <h2>Sign in</h2>
                <p class="intro">Enter your GTA email address or username to continue.</p>

                @if ($errors->any())
                    <div class="error-alert">
                        {{ $errors->first() }}
                    </div>
                @endif

                <form method="POST" action="{{ route('login') }}">
                    @csrf

                    <div class="form-group">
                        <label for="login">Email Address or Username</label>
                        <input id="login" type="text" name="login" value="{{ old('login') }}" required autofocus class="form-control" placeholder="name@gta-portal.test">
                    </div>

                    <div class="form-group">
                        <label for="password">Password</label>
                        <input id="password" type="password" name="password" required class="form-control" placeholder="&bull;&bull;&bull;&bull;&bull;&bull;&bull;&bull;">
                    </div>

                    <div class="form-options">
                        <label class="checkbox-label">
                            <input type="checkbox" name="remember" {{ old('remember') ? 'checked' : '' }}>
                            Remember me
                        </label>
                    </div>

                    <button type="submit" class="primary">Sign in to Portal &rarr;</button>
                </form>

                <div class="quick-fill">
                    <small>Development Quick Logins (Password: password)</small>
                    <div class="quick-pills">
                        <button type="button" class="quick-pill" onclick="fillForm('tutor.alex@gta-portal.test')">Tutor Alex</button>
                        <button type="button" class="quick-pill" onclick="fillForm('tutor.sam@gta-portal.test')">Tutor Sam</button>
                        <button type="button" class="quick-pill" onclick="fillForm('admin.lead@gta-portal.test')">Admin Lead</button>
                        <button type="button" class="quick-pill" onclick="fillForm('learner.one@gta-portal.test')">Learner One</button>
                        <button type="button" class="quick-pill" onclick="fillForm('employer.a@gta-portal.test')">Employer David</button>
                    </div>
                </div>
            </div>
        </section>
    </main>

    <footer>
        <span>&copy; {{ date('Y') }} Doncaster, Rotherham and District Motor Trades GTA Ltd.</span>
        <span>BUILDING SKILLS SINCE 1972</span>
    </footer>

    <script>
        function fillForm(email) {
            document.getElementById('login').value = email;
            document.getElementById('password').value = 'password';
        }
    </script>
</body>
</html>


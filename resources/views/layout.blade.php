<!doctype html>
<html lang="en-GB"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>@yield('title', 'Apprentices') · GTA local test</title><link rel="stylesheet" href="{{ asset('assets/portal.css') }}"><script src="{{ asset('assets/portal.js') }}" defer></script></head>
<body><a class="skip-link" href="#main-content">Skip to main content</a><div class="shell">
<aside class="sidebar"><a class="brand" href="{{ url('/') }}"><img src="{{ asset('assets/gta-logo.jpg') }}" width="64" height="64" alt="GTA"><span>GTA<br><small>Apprenticeship portal</small></span></a>
@auth @include('navigation.menu') @endauth
<p class="release">Local development checkpoint<br>Development Pack 19 · Fictional data only</p></aside>
<div class="workspace"><header class="topbar"><span>{{ auth()->user()?->name ?? 'GTA sign in' }} @auth<small>{{ ucfirst(auth()->user()->role) }}</small>@endauth</span><div class="toolbar"><button type="button" id="theme-toggle">Dark mode</button>@auth<form action="{{ route('logout') }}" method="post">@csrf<button>Sign out</button></form>@endauth</div></header>
<main id="main-content" tabindex="-1"><div class="test-notice">Fictional local test application · Working modules are linked below. Formal plan approval, completed reviews/signatures, complete reporting and live deployment remain in development.</div>
@if(session('status'))<div class="notice" role="status">{{ session('status') }}</div>@endif
@if(auth()->user()?->role==='ceo'&&\App\Support\ExecutiveAccess::enabled(request()))<div class="errors" role="status">CEO emergency override is on for this session. Uses are recorded. <a href="{{ route('executive.index') }}" @if(request()->routeIs('executive.*'))aria-current="page"@endif>Review or end override</a></div>@endif
@if(app()->environment('staging'))<p class="test-notice">Staging portal — use fictional test records. Live release, real-data migration and target-environment acceptance remain separate.</p>@endif
@yield('content')</main></div></div></body></html>

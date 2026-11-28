<!doctype html>
<html lang="en-GB"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>@yield('title', 'Home') · GTA Portal</title><script src="{{ asset('assets/theme-boot53.js') }}?v=53"></script><link rel="stylesheet" href="{{ asset('assets/portal.css') }}?v=109"><link rel="stylesheet" href="{{ asset('assets/theme53.css') }}?v=53"><script src="{{ asset('assets/portal.js') }}?v=109" defer></script><script src="{{ asset('assets/signin-theme50.js') }}?v=53" defer></script><link rel="stylesheet" href="{{ asset('assets/responsive64.css') }}?v=110"><script src="{{ asset('assets/page-actions64.js') }}?v=66" defer></script><link rel="stylesheet" href="{{ asset('assets/portal-workflows.css') }}?v=2"><link rel="stylesheet" href="{{ asset('assets/portal-simplification.css') }}?v=20261123-02"><script src="{{ asset('assets/portal-simplification.js') }}?v=20261123-02" defer></script><link rel="stylesheet" href="{{ asset('assets/portal-navigation.css') }}?v=109">@if(request()->routeIs('reports.index', 'dashboard.report', 'inspection.index', 'visual-reports.*', 'retention.report', 'retention.employer-attendance', 'training-gaps72.*', 'support.report', 'achievers-leavers.*', 'cohort-insights.*', 'historical-cohorts.*', 'management.*', 'timetable-management.*'))
<link rel="stylesheet" href="{{ asset('assets/report-headlines.css') }}?v=111">
@endif
@if(request()->routeIs('support.index'))
<link rel="stylesheet" href="{{ asset('assets/support-record-progress.css') }}">
@endif
<script src="{{ asset('assets/friendly-theme117-boot.js') }}?v=117"></script><link rel="stylesheet" href="{{ asset('assets/friendly-theme117.css') }}?v=117"><link rel="stylesheet" href="{{ asset('assets/report-measures118.css') }}?v=118"><script src="{{ asset('assets/friendly-theme117.js') }}?v=117" defer></script></head>
<body data-portal-theme="117" class="theme117 {{ request()->routeIs('login')?'signin-page':'portal-page' }}"><a class="skip-link" href="#main-content">Skip to main content</a><div class="shell">
<aside class="sidebar"><a class="brand" href="{{ url('/') }}"><img src="{{ asset('assets/gta-logo.jpg') }}?v=53" width="48" height="48" alt="GTA"><span>GTA Portal<br><small>Learning and progress</small></span></a>
@auth @include('navigation.menu') @endauth
@if(app()->environment('local', 'testing', 'staging'))
    <p class="release">{{ app()->environment('staging') ? 'Staging' : 'Local development' }}<br>{{ config('portal_release.build') }}</p>
@endif</aside>
<div class="workspace"><header class="topbar">@auth @can('viewAny',\App\Models\Apprentice::class)<form class="theme117-search" action="{{route('apprentices.index')}}" method="get"><label class="sr-only" for="theme117-search">Find an apprentice</label><input type="search" id="theme117-search" name="search" placeholder="Find an apprentice" value="{{request()->routeIs('apprentices.index')?request('search'):''}}"><button type="submit">Search</button></form>@endcan @endauth<span class="theme117-account">{{ auth()->user()?->name ?? 'GTA sign in' }} @auth<small>{{ \App\Support\PortalLanguage::role(auth()->user()->role) }}</small>@endauth</span><div class="toolbar"><button type="button" id="theme-toggle">Dark mode</button>@auth<form action="{{ route('logout') }}" method="post">@csrf<button>Sign out</button></form>@endauth</div></header>
<main id="main-content" tabindex="-1">@if(app()->environment('local','testing'))<div class="test-notice">Local development environment — do not enter real apprentice data.</div>@endif
@if(session('status'))<div class="notice" role="status">{{ session('status') }}</div>@endif
@if(auth()->user()?->role==='ceo'&&\App\Support\ExecutiveAccess::enabled(request()))<div class="errors" role="status">CEO emergency override is on for this session. Uses are recorded. <a href="{{ route('executive.index') }}" @if(request()->routeIs('executive.*'))aria-current="page"@endif>Review or end override</a></div>@endif
@if(app()->environment('staging'))<p class="test-notice">Staging portal — verify workflows with authorised test records before live release.</p>@endif

@if(request()->routeIs('tasks.show', 'attendance.show', 'actions.index', 'actions.create', 'actions.edit', 'reviews.create', 'reviews.edit', 'electronic-reviews.prepare-form'))
    @if($workTodayUrl = \App\Support\Workday83::returnUrl(request()))
        <p><a class="button" href="{{ $workTodayUrl }}">{{ auth()->user()->role === 'apprentice' ? 'Back to your next steps' : 'Back to Work today' }}</a></p>
    @endif
@endif
@yield('content')<footer><p><a href="{{ route('privacy.public') }}">Privacy information and contact</a></p></footer></main></div></div></body></html>

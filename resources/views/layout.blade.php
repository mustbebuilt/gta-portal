<!doctype html>
<html lang="en-GB"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>@yield('title', 'Apprentices') · GTA local test</title><link rel="stylesheet" href="{{ asset('assets/portal.css') }}"><script src="{{ asset('assets/portal.js') }}" defer></script></head>
<body><a class="skip-link" href="#main-content">Skip to main content</a><div class="shell">
<aside class="sidebar"><a class="brand" href="{{ url('/') }}"><img src="{{ asset('assets/gta-logo.jpg') }}" width="64" height="64" alt="GTA"><span>GTA<br><small>Apprenticeship portal</small></span></a>
@auth<button type="button" id="navigation-toggle" class="navigation-toggle" aria-controls="portal-navigation" aria-expanded="true" hidden>Hide menu</button><nav id="portal-navigation" aria-label="Main navigation">
@if(in_array(auth()->user()->role,['admin','ceo','tutor','apprentice'],true))<a href="{{ route('dashboard.index') }}" @if(request()->routeIs('dashboard.*'))aria-current="page"@endif>Dashboard</a>@endif
@if(\App\Support\TaskLibrary::allowed(auth()->user()))<a href="{{ route('task-library.index') }}" @if(request()->routeIs('task-library.*'))aria-current="page"@endif>Task library</a>@endif
@can('viewAny',\App\Models\Apprentice::class)<a href="{{ route('apprentices.index') }}" @if(request()->routeIs('apprentices.*'))aria-current="page"@endif>Apprentices</a>@endcan
@if(in_array(auth()->user()->role,['admin','ceo','tutor','apprentice','functional_skills_tutor'],true))<a href="{{ route('em.caseload') }}" @if(request()->routeIs('em.*'))aria-current="page"@endif>Maths and English</a>@endif
@if(in_array(auth()->user()->role,['admin','ceo','tutor'],true))<a href="{{ route('attendance.index') }}" @if(request()->routeIs('attendance.*'))aria-current="page"@endif>Groups and sessions</a><a href="{{ route('marking.index') }}" @if(request()->routeIs('marking.*'))aria-current="page"@endif>Marking worklist</a>@endif
@if(in_array(auth()->user()->role,['admin','ceo','apprentice','careers_adviser'],true))<a href="{{ route('careers.worklist') }}" @if(request()->routeIs('careers.*'))aria-current="page"@endif>Careers</a>@endif
@if(\App\Support\SupportAccess::team(auth()->user()))<a href="{{ route('support.worklist') }}" @if(request()->routeIs('support.*') && !request()->routeIs('support.permissions'))aria-current="page"@endif>Support and retention</a>@endif
@if(in_array(auth()->user()->role,['admin','ceo','tutor','mentor'],true))<a href="{{ route('reviews.index') }}" @if(request()->routeIs('reviews.*'))aria-current="page"@endif>Reviews</a>@endif
@if(!in_array(auth()->user()->role,['apprentice','employer'],true))<a href="{{ route('staff.index') }}" @if(request()->routeIs('staff.*'))aria-current="page"@endif>Staff development</a>@endif
@if(auth()->user()->role!=='apprentice')<a href="{{ route('cases.index') }}" @if(request()->routeIs('cases.*'))aria-current="page"@endif>Case studies</a>@endif
@if(in_array(auth()->user()->role,['admin','ceo'],true))<a href="{{ route('support.permissions') }}" @if(request()->routeIs('support.permissions'))aria-current="page"@endif>Support permissions</a>@endif
@can('manage-directories')
<a href="{{ route('curriculum-sources.index') }}" @if(request()->routeIs('curriculum-sources.*'))aria-current="page"@endif>Curriculum source checks</a>
<a href="{{ route('evidence-restore.index') }}" @if(request()->routeIs('evidence-restore.*'))aria-current="page"@endif>Evidence recovery rehearsals</a>
<a href="{{ route('reminder-mail.index') }}" @if(request()->routeIs('reminder-mail.*'))aria-current="page"@endif>Reminder mail pilot</a>
<a href="{{ route('onboarding.index') }}" @if(request()->routeIs('onboarding.*'))aria-current="page"@endif>Staff onboarding</a>
@foreach(['employers'=>'Employers','staff'=>'Staff','groups'=>'Tutor groups','programmes'=>'Programmes'] as $kind=>$title)<a href="{{ route('directories.index',$kind) }}" @if(request()->routeIs('directories.*') && request()->route('kind')===$kind)aria-current="page"@endif>{{ $title }}</a>@endforeach
@endcan
@if(auth()->user()->role==='ceo')<a href="{{ route('executive.index') }}" @if(request()->routeIs('executive.*'))aria-current="page"@endif>CEO emergency access</a>@endif
@if(\App\Support\SecondFactor::required(auth()->user()) || \App\Support\SecondFactor::mayEnrol(auth()->user()))<a href="{{ route('second-factor.settings') }}" @if(request()->routeIs('second-factor.settings','second-factor.change'))aria-current="page"@endif>Two-step sign-in</a>@endif
<a href="{{ route('account.email') }}" @if(request()->routeIs('account.email*'))aria-current="page"@endif>Confirm email</a><a href="{{ route('account.password') }}" @if(request()->routeIs('account.password*'))aria-current="page"@endif>Change password</a><a href="{{ route('help.index') }}" @if(request()->routeIs('help.*'))aria-current="page"@endif>Help and how to</a></nav>@endauth
<p class="release">Local development checkpoint<br>Development Pack 10 · Fictional data only</p></aside>
<div class="workspace"><header class="topbar"><span>{{ auth()->user()?->name ?? 'GTA sign in' }} @auth<small>{{ ucfirst(auth()->user()->role) }}</small>@endauth</span><div class="toolbar"><button type="button" id="theme-toggle">Dark mode</button>@auth<form action="{{ route('logout') }}" method="post">@csrf<button>Sign out</button></form>@endauth</div></header>
<main id="main-content" tabindex="-1"><div class="test-notice">Fictional local test application · Working modules are linked below. Formal plan approval, completed reviews/signatures, complete reporting and live deployment remain in development.</div>
@if(session('status'))<div class="notice" role="status">{{ session('status') }}</div>@endif
@if(auth()->user()?->role==='ceo'&&\App\Support\ExecutiveAccess::enabled(request()))<div class="errors" role="status">CEO emergency override is on for this session. Uses are recorded. <a href="{{ route('executive.index') }}" @if(request()->routeIs('executive.*'))aria-current="page"@endif>Review or end override</a></div>@endif
@if(app()->environment('staging'))<p class="test-notice">Staging portal — use fictional test records. Live release, real-data migration and target-environment acceptance remain separate.</p>@endif
@yield('content')</main></div></div></body></html>

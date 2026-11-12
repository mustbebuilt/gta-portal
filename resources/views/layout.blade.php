<!doctype html>
<html lang="en-GB"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>@yield('title', 'Apprentices') · GTA local test</title><link rel="stylesheet" href="{{ asset('assets/portal.css') }}"><script src="{{ asset('assets/portal.js') }}" defer></script></head>
<body><div class="shell">
<aside class="sidebar"><a class="brand" href="{{ url('/') }}"><img src="{{ asset('assets/gta-logo.jpg') }}" width="64" height="64" alt="GTA"><span>GTA<br><small>Apprenticeship portal</small></span></a>
@auth<nav aria-label="Main navigation">
@if(in_array(auth()->user()->role,['admin','ceo','tutor','apprentice'],true))<a href="{{ route('dashboard.index') }}">Dashboard</a>@endif
@can('viewAny',\App\Models\Apprentice::class)<a href="{{ route('apprentices.index') }}" @if(request()->routeIs('apprentices.*'))aria-current="page"@endif>Apprentices</a>@endcan
@if(in_array(auth()->user()->role,['admin','ceo','tutor','apprentice','functional_skills_tutor'],true))<a href="{{ route('em.caseload') }}">Maths and English</a>@endif
@if(in_array(auth()->user()->role,['admin','ceo','tutor'],true))<a href="{{ route('attendance.index') }}">Groups and sessions</a>@endif
@if(in_array(auth()->user()->role,['admin','ceo','apprentice','careers_adviser'],true))<a href="{{ route('careers.worklist') }}">Careers</a>@endif
@if(in_array(auth()->user()->role,['admin','ceo','inclusion_lead','inclusion_member'],true))<a href="{{ route('support.worklist') }}">Support and retention</a>@endif
@if(in_array(auth()->user()->role,['admin','ceo','tutor','mentor'],true))<a href="{{ route('reviews.index') }}">Reviews</a>@endif
@if(!in_array(auth()->user()->role,['apprentice','employer'],true))<a href="{{ route('staff.index') }}">Staff development</a>@endif
@if(auth()->user()->role!=='apprentice')<a href="{{ route('cases.index') }}">Case studies</a>@endif
@can('manage-directories')
@foreach(['employers'=>'Employers','staff'=>'Staff','groups'=>'Tutor groups','programmes'=>'Programmes'] as $kind=>$title)<a href="{{ route('directories.index',$kind) }}" @if(request()->routeIs('directories.*') && request()->route('kind')===$kind)aria-current="page"@endif>{{ $title }}</a>@endforeach
@endcan
@if(auth()->user()->role==='ceo')<a href="{{ route('executive.index') }}">CEO emergency access</a>@endif
<a href="{{ route('help.index') }}">Help and how to</a></nav>@endauth
<p class="release">Local development checkpoint<br>v0.7.0 · Fictional data only</p></aside>
<div class="workspace"><header class="topbar"><span>{{ auth()->user()?->name ?? 'GTA sign in' }} @auth<small>{{ ucfirst(auth()->user()->role) }}</small>@endauth</span><div class="toolbar"><button type="button" id="theme-toggle">Dark mode</button>@auth<form action="{{ route('logout') }}" method="post">@csrf<button>Sign out</button></form>@endauth</div></header>
<main><div class="test-notice">Fictional local test application · Working modules are linked below. Formal plan approval, completed reviews/signatures, complete reporting and live deployment remain in development.</div>
@if(session('status'))<div class="notice" role="status">{{ session('status') }}</div>@endif
@if(auth()->user()?->role==='ceo'&&\App\Support\ExecutiveAccess::enabled(request()))<div class="errors" role="status">CEO emergency override is on for this session. Uses are recorded. <a href="{{ route('executive.index') }}">Review or end override</a></div>@endif
@yield('content')</main></div></div></body></html>

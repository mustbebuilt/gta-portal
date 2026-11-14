<nav id="portal-navigation" aria-label="Main navigation">
@if(\App\Support\MyResponsibilities::allowed(auth()->user()))<a href="{{ route('responsibilities.index') }}" @if(request()->routeIs('responsibilities.*'))aria-current="page"@endif>My responsibilities</a>@endif
@if(in_array(auth()->user()->role,['admin','ceo','tutor','apprentice'],true))<a href="{{ route('dashboard.index') }}" @if(request()->routeIs('dashboard.*'))aria-current="page"@endif>Dashboard</a>@endif
@if(\App\Support\TaskLibrary::allowed(auth()->user()))<a href="{{ route('task-library.index') }}" @if(request()->routeIs('task-library.*'))aria-current="page"@endif>Task library</a>@endif
@can('viewAny',\App\Models\Apprentice::class)<a href="{{ route('apprentices.index') }}" @if(request()->routeIs('apprentices.*'))aria-current="page"@endif>Apprentices</a>@endcan
@if(in_array(auth()->user()->role,['admin','ceo','tutor','apprentice','functional_skills_tutor'],true)||\App\Support\StaffDuties::has(auth()->user(),'maths_tutor')||\App\Support\StaffDuties::has(auth()->user(),'english_tutor'))<a href="{{ route('em.caseload') }}" @if(request()->routeIs('em.*'))aria-current="page"@endif>Maths and English</a>@endif
@if(in_array(auth()->user()->role,['admin','ceo','tutor'],true))<a href="{{ route('attendance.index') }}" @if(request()->routeIs('attendance.*'))aria-current="page"@endif>Groups and sessions</a><a href="{{ route('marking.index') }}" @if(request()->routeIs('marking.*'))aria-current="page"@endif>Marking worklist</a>@endif
@if((in_array(auth()->user()->role,['admin','ceo','apprentice'],true) || \App\Support\StaffDuties::has(auth()->user(),'careers_adviser')))<a href="{{ route('careers.worklist') }}" @if(request()->routeIs('careers.*'))aria-current="page"@endif>Careers</a>@endif
@if(\App\Support\SupportAccess::team(auth()->user()))<a href="{{ route('support-review-reminders.index') }}" @if(request()->routeIs('support-review-reminders.*'))aria-current="page"@endif>Support review reminders</a>@endif
@if(\App\Support\SupportAccess::team(auth()->user()))<a href="{{ route('support.worklist') }}" @if(request()->routeIs('support.*') && !request()->routeIs('support.permissions'))aria-current="page"@endif>Support and retention</a>@endif
@if(\App\Support\QuestionnaireLinks::canView(auth()->user()))<a href="{{ route('questionnaires.index') }}" @if(request()->routeIs('questionnaires.index'))aria-current="page"@endif>Questionnaires</a>@endif
@if(\App\Support\ReviewReminders::allowed(auth()->user()))<a href="{{ route('reviews.index') }}" @if(request()->routeIs('reviews.*'))aria-current="page"@endif>Reviews</a>@endif
@if(!in_array(auth()->user()->role,['apprentice','employer'],true))<a href="{{ route('staff.index') }}" @if(request()->routeIs('staff.*'))aria-current="page"@endif>Staff development</a>@endif
@if(auth()->user()->role!=='apprentice')<a href="{{ route('cases.index') }}" @if(request()->routeIs('cases.*'))aria-current="page"@endif>Case studies</a>@endif
@if(in_array(auth()->user()->role,['admin','ceo'],true))<a href="{{ route('support.permissions') }}" @if(request()->routeIs('support.permissions'))aria-current="page"@endif>Support permissions</a>@endif
@can('manage-directories')
<a href="{{ route('setup.index') }}" @if(request()->routeIs('setup.*'))aria-current="page"@endif>Portal setup</a>
<a href="{{ route('questionnaires.configuration') }}" @if(request()->routeIs('questionnaires.configuration'))aria-current="page"@endif>Questionnaire link setup</a>
<a href="{{ route('curriculum-sources.index') }}" @if(request()->routeIs('curriculum-sources.*'))aria-current="page"@endif>Curriculum source checks</a>
<a href="{{ route('evidence-restore.index') }}" @if(request()->routeIs('evidence-restore.*'))aria-current="page"@endif>Evidence recovery rehearsals</a>
<a href="{{ route('reminder-mail.index') }}" @if(request()->routeIs('reminder-mail.*'))aria-current="page"@endif>Reminder mail pilot</a>
<a href="{{ route('onboarding.index') }}" @if(request()->routeIs('onboarding.*'))aria-current="page"@endif>Staff onboarding</a>
@foreach(['employers'=>'Employers','staff'=>'Staff','groups'=>'Tutor groups','programmes'=>'Programmes'] as $kind=>$title)<a href="{{ route('directories.index',$kind) }}" @if(request()->routeIs('directories.*') && request()->route('kind')===$kind)aria-current="page"@endif>{{ $title }}</a>@endforeach
@endcan
@if(auth()->user()->role==='ceo')<a href="{{ route('executive.index') }}" @if(request()->routeIs('executive.*'))aria-current="page"@endif>CEO emergency access</a>@endif
@if(\App\Support\SecondFactor::required(auth()->user()) || \App\Support\SecondFactor::mayEnrol(auth()->user()))<a href="{{ route('second-factor.settings') }}" @if(request()->routeIs('second-factor.settings','second-factor.change'))aria-current="page"@endif>Two-step sign-in</a>@endif
<a href="{{ route('account.email') }}" @if(request()->routeIs('account.email*'))aria-current="page"@endif>Confirm email</a><a href="{{ route('account.password') }}" @if(request()->routeIs('account.password*'))aria-current="page"@endif>Change password</a><a href="{{ route('help.index') }}" @if(request()->routeIs('help.*'))aria-current="page"@endif>Help and how to</a></nav>

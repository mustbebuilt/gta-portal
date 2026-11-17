<nav class="page-actions64" aria-label="Actions for this apprentice">
<div class="toolbar">
@can('update',$apprentice)<a class="button primary" href="{{ route('apprentices.edit',$apprentice) }}">Check details and assignments</a>
@else
@if(auth()->user()->role==='apprentice')<a class="button primary" href="{{ route('experience.home') }}">My next step</a>
@else<a class="button primary" href="{{ route('learning.index',$apprentice) }}">Open training tasks</a>@endif
@endcan
@if(\App\Support\LearningAccess::manager(auth()->user()))<a class="button" href="{{ route('learning.index',$apprentice) }}">Training tasks</a>@endif
@if(auth()->user()->role==='apprentice')<a class="button" href="{{ route('learning.index',$apprentice) }}">My training tasks</a><a class="button" href="{{ route('experience.index') }}#new-help">I need help</a>
@else<a class="button" href="{{ route('reviews.create',$apprentice) }}">Progress reviews</a><a class="button" href="{{ route('support.index',$apprentice) }}">{{ \App\Support\SupportAccess::team(auth()->user(),$apprentice)?'Support plan and markers':'Raise / view concerns' }}</a>@endif
<a class="button" href="{{ route('attendance.apprentice',$apprentice) }}">Attendance and OTJ</a>
</div>
<details class="action-disclosure64"><summary>More actions</summary><div class="action-groups64">
<section><h2>Learning and progress</h2><ul>
<li><a href="{{ route('delivery.show',$apprentice) }}">Individual delivery plan and block calendar</a></li>
<li><a href="{{ route('plans.show',$apprentice) }}">Training-plan preparation</a></li>
<li><a href="{{ route('journey.show',$apprentice) }}">Apprenticeship journey</a></li>
<li><a href="{{ route('em.index',$apprentice) }}">Maths and English</a></li>
<li><a href="{{ route('actions.index',$apprentice) }}">Action plan</a></li>
<li><a href="{{ route('scorecards.index',$apprentice) }}">Monthly learning snapshot</a></li>
@if(\App\Support\GatewayReadiness::allowed(auth()->user(),$apprentice))<li><a href="{{ route('gateway.show',$apprentice) }}">Gateway preparation checks</a></li>@endif
</ul></section>
<section><h2>Support and communication</h2><ul>
@if(in_array(auth()->user()->role,['tutor','admin','ceo'],true))<li><a href="{{ route('support.instructions',$apprentice) }}">Teaching instructions</a></li>@endif
@if(\App\Support\LearningAccess::manager(auth()->user()) || \App\Support\StaffDuties::assigned(auth()->user(),$apprentice,'careers_adviser') || \App\Support\LearningAccess::learner(auth()->user(),$apprentice))<li><a href="{{ route('careers.index',$apprentice) }}">Careers</a></li>@endif
@if(\App\Support\ReviewAppointments::staff(auth()->user(),$apprentice))<li><a href="{{ route('review-appointments.form',$apprentice) }}">Book review appointment</a></li>@endif
@if(\App\Support\EmployerActions::staff(auth()->user(),$apprentice))<li><a href="{{ route('employer-actions.staff',$apprentice) }}">Employer requests and replies</a></li>@endif
@if(auth()->user()->role==='apprentice')<li><a href="{{ route('alerts.index') }}">Change my alert preferences</a></li>@endif
</ul></section>
@if(\App\Support\LearningAccess::manager(auth()->user()))<section><h2>Administration</h2><ul>
<li><a href="{{ route('apprentice-onboarding.show',$apprentice) }}">Set up apprentice sign-in</a></li>
<li><a href="{{ route('employer-grants.index',$apprentice) }}">Verify employer access</a></li>
<li><a href="{{ route('retention.entry',$apprentice) }}">Recruitment and starting circumstances</a></li>
<li><a href="{{ route('retention.timeline',$apprentice) }}">Retention chronology</a></li>
</ul></section>@endif
@if(\App\Support\PortalActions::hasPerson(auth()->user(),$apprentice))<p><a href="{{ route('quick-actions.person',$apprentice) }}">All available actions for this apprentice</a></p>@endif
</div></details></nav>

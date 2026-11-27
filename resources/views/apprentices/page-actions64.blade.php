<nav class="action-groups64" aria-label="Additional apprentice actions">
<section><h2>Learning and progress</h2><ul>
<li><a href="{{ route('delivery.show',$apprentice) }}">Individual delivery plan and block calendar</a></li>
<li><a href="{{ route('plans.show',$apprentice) }}">Training-plan preparation</a></li>
<li><a href="{{ route('training-journey72.show',$apprentice) }}">Personal training bank and gaps</a></li>
<li><a href="{{ route('progress-review74.show',$apprentice) }}">Apprentice Progress Review summary</a></li>
<li><a href="{{ route('scorecards.index',$apprentice) }}">Monthly learning snapshot</a></li>
@if(\App\Support\GatewayReadiness::allowed(auth()->user(),$apprentice))<li><a href="{{ route('gateway.show',$apprentice) }}">Gateway preparation checks</a></li>@endif
</ul></section>
<section><h2>Support and communication</h2><ul>
<li><a href="{{ route('support.index',$apprentice) }}">{{ \App\Support\SupportAccess::team(auth()->user(),$apprentice)?'Support and retention':'Raise or view a concern' }}</a></li>
@if(in_array(auth()->user()->role,['tutor','admin','ceo'],true))<li><a href="{{ route('support.instructions',$apprentice) }}">Teaching instructions</a></li>@endif
@if(\App\Support\LearningAccess::manager(auth()->user()) || \App\Support\StaffDuties::assigned(auth()->user(),$apprentice,'careers_adviser') || \App\Support\LearningAccess::learner(auth()->user(),$apprentice))<li><a href="{{ route('careers.index',$apprentice) }}">Careers</a></li>@endif
@if(\App\Support\ReviewAppointments::staff(auth()->user(),$apprentice))<li><a href="{{ route('review-appointments.form',$apprentice) }}">Book review appointment</a></li>@endif
@if(\App\Support\EmployerActions::staff(auth()->user(),$apprentice))<li><a href="{{ route('employer-actions.staff',$apprentice) }}">Employer requests and replies</a></li>@endif
@if(auth()->user()->role==='apprentice')<li><a href="{{ route('alerts.index') }}">Change my alert preferences</a></li>@endif
</ul></section>
@if(\App\Support\LearningAccess::manager(auth()->user()))<section><h2>Administration</h2><ul>
<li><a href="{{ route('apprentices.edit', $apprentice) }}">Edit apprentice</a></li>
@unless($archived)
    <li><a href="{{ route('employer68.show', ['apprentice'=>$apprentice->id]) }}">{{ $apprentice->employerLabel() ? 'Change employer' : 'Link employer' }}</a></li>
@endunless
<li><a href="{{ route('apprentices.close-down', $apprentice) }}">Programme outcome</a></li>
<li><a href="{{ route('apprentice-onboarding.show',$apprentice) }}">Set up apprentice sign-in</a></li>
<li><a href="{{ route('retention.entry',$apprentice) }}">Recruitment and starting circumstances</a></li>
<li><a href="{{ route('retention.timeline',$apprentice) }}">Retention chronology</a></li>

</ul></section>@endif
@if(\App\Support\PortalActions::hasPerson(auth()->user(),$apprentice))<p><a href="{{ route('quick-actions.person',$apprentice) }}">Other available tools</a></p>@endif
</nav>

<section class="panel" aria-labelledby="journey-next-heading"><h2 id="journey-next-heading">What happens next?</h2>
<p>These are current actions and planned dates. They do not move the vehicle along the road.</p>
<div class="dashboard-grid">
<section><h3>Next action</h3>
@if($nextStep['next'])
<p class="recorded-answer">{{ $nextStep['next']['title'] }}</p><p>Due: {{ $nextStep['next']['due']?\Carbon\Carbon::parse($nextStep['next']['due'])->format('d/m/Y'):'No agreed deadline recorded' }}</p><a class="button primary" href="{{ $nextStep['next']['url'] }}">{{ $nextStep['next']['label'] }}</a>
@else
<p>No editable task or available apprentice action is selected. {{ $nextStep['awaiting'] }} task(s) await marking. Ask the Tutor about paper assessments, unreleased blocks or missing source content.</p><a class="button" href="{{ route('learning.index',$apprentice) }}">Check training tasks</a>
@endif
</section>
<section><h3>Next training day</h3>
@if($nextStep['session'])
<p class="recorded-answer">{{ $nextStep['session']->name }}</p><p><time datetime="{{ $nextStep['session']->session_date->toDateString() }}">{{ $nextStep['session']->session_date->format('d/m/Y') }}</time> · {{ $nextStep['session']->start_time }}–{{ $nextStep['session']->end_time }} ({{ config('app.timezone') }})</p><p>Saved training session; attendance and off-the-job training remain separate records.</p>
@else
<p>No current upcoming training session with a valid saved roster and times is available here. Confirm the timetable with GTA.</p>
@endif
@if(\App\Support\LearningAccess::learner(auth()->user(),$apprentice))
<a class="button" href="{{ route('calendar.index') }}">Open my calendar</a>
@else
<a class="button" href="{{ route('attendance.apprentice',$apprentice) }}">Open apprentice attendance</a>
@endif
</section>
<section><h3>Next review</h3>
@if($appointment)
<p><strong>Booked review appointment</strong></p><p>{{ $appointment->starts_at->copy()->timezone(config('app.timezone'))->format('d/m/Y H:i') }}–{{ $appointment->ends_at->copy()->timezone(config('app.timezone'))->format('H:i') }} ({{ config('app.timezone') }})</p><a class="button" href="{{ route('review-appointments.show',$appointment) }}">Open review appointment</a><p>Publication is not confirmation that everyone can attend, that the meeting happened or that the review is signed.</p>
@else
<p>No current published review appointment is available here. Confirm the booking with the Tutor.</p>
@endif
@if($appointmentLimited)<p>There are more than 50 upcoming published appointment records. Ask GTA to reconcile the appointment list.</p>@endif
@if($nextStep['review']['next_due'])
<p>Review due reference: <strong>{{ \Carbon\Carbon::parse($nextStep['review']['next_due'])->format('d/m/Y') }}</strong> · {{ $nextStep['review']['status'] }}</p><p>This reference is 10 weeks after the latest reliable checked review meeting. It is separate from the booked appointment.</p>
@else
<p>The tracked next-review reference needs checking with the Tutor. A missing reference does not mean a review is unnecessary.</p>
@endif
@if($nextStep['review']['integrity_checks']||$reviewChecks)<p class="test-notice">A checked review record needs source checking. It does not add a milestone or establish a reliable next-review reference here.</p>@endif
</section>
</div>
@if(\App\Support\LearningAccess::learner(auth()->user(),$apprentice))<p><a href="{{ route('experience.home') }}">Open My next step and all outstanding actions</a></p>@endif
</section>

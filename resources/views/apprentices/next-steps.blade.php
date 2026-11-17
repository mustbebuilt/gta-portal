<section class="panel" aria-labelledby="apprentice-next-step"><h2 id="apprentice-next-step">What to do next</h2>
@if(\App\Support\LearningAccess::manager(auth()->user()))<p>Check this apprentice's programme, dates, employer and staff assignments. Then open Training blocks and tasks to check the correct source allocation, and set up the apprentice's own sign-in separately.</p><p>Saving a profile does not create a login, assign tasks or approve a training plan.</p>
@elseif(auth()->user()->role==='tutor')<p>Open this apprentice's training tasks to check submitted work and feedback, or their review history to prepare the next review. Assignment corrections need an administrator.</p>
@elseif(auth()->user()->role==='apprentice')<p>Use My next step to find your next available action, or open your training tasks to continue saved work and read Tutor feedback.</p>
@endif
</section>
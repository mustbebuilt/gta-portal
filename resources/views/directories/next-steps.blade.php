<section class="panel" aria-labelledby="directory-next-step"><h2 id="directory-next-step">What to do next</h2>
@if($kind==='employers')<p>An employer record does not create a personal login.</p><div class="toolbar"><a class="button" href="{{ route('apprentices.index') }}">Assign employer on an apprentice</a><a class="button" href="{{ route('employer-accounts.index') }}">Set up named employer contacts</a></div>
@elseif($kind==='staff')<p>Assign staff on apprentice profiles. Use Staff onboarding for a sign-in invitation.</p><a class="button" href="{{ route('apprentices.index') }}">Assign staff on an apprentice</a>
@elseif($kind==='programmes')<p>Check the standard/version, then assign the programme. Tasks and plans are set separately.</p><div class="toolbar"><a class="button" href="{{ route('directories.index','groups') }}">Check tutor groups</a><a class="button" href="{{ route('apprentices.index') }}">Assign programme on an apprentice</a></div>
@elseif($kind==='groups')<p>Check each apprentice’s programme and tutor after changing their group.</p><a class="button" href="{{ route('apprentices.index') }}">Assign tutor group on an apprentice</a>
@endif
</section>
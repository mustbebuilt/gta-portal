@extends('layout')
@section('title','Apprentice account setup')
@section('content')
<header class="page-heading">
    <div><p class="eyebrow">{{ $apprentice->reference }}</p><h1>Apprentice login and password help</h1><p>{{ $apprentice->first_name }} {{ $apprentice->last_name }}</p></div>
    <a class="button" href="{{ route('apprentices.show', $apprentice) }}">Back to apprentice record</a>
</header>
@include('learning.errors')
@if(!$user)<section class="panel"><h2>Set up first-time access</h2>
<p>Profile email · {{ $apprentice->email ?: 'Email not recorded' }}. <a href="{{ route('apprentices.edit', $apprentice) }}">Check or correct the profile email</a>.</p><p>Confirm identity and their individual email with the apprentice. An email match alone is not identity evidence. Shared employer or staff logins cannot be used.</p>
<form method="post" action="{{ route('apprentice-onboarding.link',$apprentice) }}">@csrf<input type="hidden" name="version" value="{{ $apprentice->version }}"><input type="hidden" name="mode" value="new"><label for="new-reference">Identity check reference (GTA internal reference; avoid sensitive details)</label><textarea id="new-reference" name="identity_reference" maxlength="2000" required></textarea><label><input type="checkbox" name="confirmed" value="1" required> I checked this person's identity and individual email.</label><button>Create and link login</button></form><p>Once linked, send the invitation so the apprentice can choose their password.</p>
@foreach($candidates as $candidate)<details><summary>Link existing apprentice login #{{ $candidate->id }}: {{ $candidate->name }} · {{ $candidate->email }}</summary><form method="post" action="{{ route('apprentice-onboarding.link',$apprentice) }}">@csrf<input type="hidden" name="version" value="{{ $apprentice->version }}"><input type="hidden" name="mode" value="existing"><input type="hidden" name="existing_user_id" value="{{ $candidate->id }}"><input type="hidden" name="record_version" value="{{ $candidate->record_version }}"><label for="existing-reference-{{ $candidate->id }}">Identity check reference</label><textarea id="existing-reference-{{ $candidate->id }}" name="identity_reference" maxlength="2000" required></textarea><label><input type="checkbox" name="confirmed" value="1" required> I checked this is the apprentice's own existing account.</label><button>Link this exact existing account</button></form></details>@endforeach</section>
@else<section class="panel"><h2>Linked account #{{ $user->id }}</h2><p>{{ $user->name }} · {{ $user->email }} · {{ $user->active?'Active':'Inactive' }} · {{ $user->email_verified_at?'Mailbox confirmed':'Mailbox unconfirmed' }}</p>
@if(!$user->active || $user->role !== 'apprentice' || !\App\Support\ApprenticeOnboarding::inviteProfile($user))
    <h3>Check the account link</h3>
    <p>Check that the active apprentice login belongs to this person and matches their individual profile email.</p>
@elseif($user->email_verified_at)
    <h3>Forgotten password</h3>
    @if(\App\Support\AccountAccess::recoveryAvailable() && \App\Support\AccountAccess::eligible($user->email)?->id === $user->id)
        <p>Ask the apprentice to open the portal sign-in page, choose <strong>Forgotten your password?</strong> and enter their login email shown above.</p>
    @else
        <p>Password reset email is currently unavailable. Ask the GTA portal administrator to check password recovery and email delivery.</p>
    @endif
@elseif(\App\Support\AccountOnboarding::eligible($user, 'learner_invite'))
    <h3>Send the password setup invitation</h3>
    <p>The apprentice chooses their password using the link in the invitation.</p>
    <form method="post" action="{{ route('apprentice-onboarding.invite',$apprentice) }}">@csrf<input type="hidden" name="version" value="{{ $apprentice->version }}"><input type="hidden" name="record_version" value="{{ $user->record_version }}"><button class="primary">{{ $token ? 'Resend login invitation' : 'Send login invitation' }}</button></form>
@else
    <h3>Login invitation unavailable</h3>
    <p>Ask the GTA portal administrator to check the account and invitation email delivery settings.</p>
@endif
@if($token)<p>Last invitation prepared {{ $token->created_at }}; expires {{ $token->expires_at }}. Prepared does not mean delivered or accepted.</p>@endif
@if($token)<form method="post" action="{{ route('apprentice-onboarding.revoke',$apprentice) }}">@csrf<input type="hidden" name="version" value="{{ $apprentice->version }}"><button>Revoke outstanding invitation</button></form>@endif</section>
<details class="panel"><summary>Correct a wrong account link</summary><p>Use only after checking the identity problem. This invalidates sessions and links and removes this profile's login link. It retains the account, training data and historical checklist records. Previous checkmarks are not transferred to another account.</p><form method="post" action="{{ route('apprentice-onboarding.detach',$apprentice) }}">@csrf<input type="hidden" name="version" value="{{ $apprentice->version }}"><label for="unlink-reference">Correction reference</label><textarea id="unlink-reference" name="identity_reference" maxlength="2000" required></textarea><label><input type="checkbox" name="confirmed" value="1" required> I checked this link must be removed.</label><button>Unlink and invalidate account sessions</button></form></details>@endif
<section class="panel"><h2>Check this is the right person</h2><p>{{ $apprentice->reference }} · {{ $apprentice->email??'Email not recorded' }} · {{ $apprentice->programmeLabel()??'Programme not assigned' }}</p><p>{{ $apprentice->employerLabel()??'Employer not assigned' }} · {{ $readiness['state'] }}</p><ul>@foreach($readiness['issues'] as $issue)<li>{{ $issue }}</li>@endforeach</ul><a class="button" href="{{ route('apprentices.edit',$apprentice) }}">Correct profile and assignments</a><p>Readiness is a setup aid. It does not approve eligibility or replace formal induction. Alert preferences stay under the existing controls.</p></section>
<section class="panel"><h2>Apprentice's getting started checks</h2><p>{{ $readiness['confirmed'] }}/{{ $readiness['total'] }} confirmed by the currently linked apprentice. Staff cannot tick these on their behalf.</p><ul>@foreach($checks as $check)<li>{{ $check['label'] }}: {{ $check['confirmed_at']?'Confirmed '.$check['confirmed_at']:'Not confirmed' }}</li>@endforeach</ul><p>These acknowledgements do not prove task completion, reminder delivery, a signed review or formal induction.</p></section>
<section class="panel"><h2>Account link history</h2>@forelse($events as $event)<p>{{ $event->created_at }} · {{ $event->event }} · staff account #{{ $event->actor_id }} · apprentice login #{{ $event->user_id }}</p>@empty<p>No account link events recorded by this workflow.</p>@endforelse<p>Identity references are retained privately and are not displayed on this page.</p></section>
@endsection

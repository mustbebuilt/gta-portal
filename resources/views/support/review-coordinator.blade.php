@if($plan->exists && $plan->status==='Active')
<section class="panel"><h2>Review coordinator</h2>
@php($assigned=\App\Models\User::find($plan->review_owner_id))
<p>{{ $assigned?->name ?? 'Not assigned' }}@if($assigned && !\App\Support\SupportAccess::writer($assigned,$apprentice)) · Access needs checking @endif</p>
<p>The coordinator receives an in-portal prompt when an approved plan review is due. The reminder does not complete the review or send an email by itself.</p>
@if($lead)<form method="post" action="{{ route('support-review-reminders.assign',$plan) }}">@csrf<input type="hidden" name="version" value="{{ $plan->version }}"><label for="review-coordinator">Inclusion Team coordinator</label><select id="review-coordinator" name="coordinator"><option value="">Not assigned</option>@foreach(\App\Support\SupportAccess::owners($apprentice)->filter(fn($u)=>\App\Support\SupportAccess::writer($u,$apprentice)) as $owner)<option value="{{ $owner->id }}" @selected($plan->review_owner_id===$owner->id)>{{ $owner->name }}</option>@endforeach</select><button>Save coordinator</button></form>@endif
<p><a class="button" href="{{ route('support-review-reminders.index') }}">My support review reminders</a></p></section>
@endif

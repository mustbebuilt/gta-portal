@extends('layout')
@section('title','Apprentice intake')
@section('content')
<h1>Apprentice intake</h1>@include('learning.errors')<p><a class="button" href="{{ route('cohort-setup.index') }}">Existing cohort readiness</a> <a href="{{ route('setup-cases.index') }}">Setup follow-up actions</a></p><p>Prepare a whole group of new apprentice profiles with a checked source. Existing profiles are never overwritten or matched by name.</p><p><a class="button" href="{{ route('intakes.form') }}">Prepare intake upload</a> <a href="{{ route('intakes.catalogue') }}">Check programme and staff references</a> <a href="{{ route('apprentice-onboarding.index') }}">Account setup</a></p>
@forelse($batches as $b)<section class="panel"><h2><a href="{{ route('intakes.show',$b) }}">Intake {{ $b->created_at }}</a></h2><p>{{ $b->state }} · creator #{{ $b->created_by }} · preview expires {{ $b->expires_at }} UTC.</p></section>@empty<p>No intake previews yet.</p>@endforelse
{{ $batches->links() }}
@endsection

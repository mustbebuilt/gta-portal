@extends('layout')
@section('title','Checked session changes')
@section('content')
<h1>Checked session changes</h1><p><a href="{{ route('session-operations.index') }}">Register changes</a> · <a href="{{ route('session-changes.form') }}">Prepare a future-session change</a></p><p>Applied means a historical checked operation, not a claim the current timetable is unchanged.</p>@forelse($batches as $b)<section class="panel"><h2><a href="{{ route('session-changes.show',$b) }}">{{ \App\Support\TimetableChanges::MODES[$b->mode]??'Evidence needs checking' }}</a></h2><p>{{ $b->state }} · prepared {{ $b->created_at }} · preview version {{ $b->version }}</p></section>@empty<p>No checked change previews.</p>@endforelse
{{ $batches->links() }}
@endsection

@extends('layout')
@section('title','Assign questionnaires')
@section('content')
<header class="page-heading"><h1>Assign questionnaires</h1></header><a class="button" href="{{ route('portal-questionnaires.index') }}">Back to worklist</a>
@if($errors->any())<div class="errors" role="alert">@foreach($errors->all() as $error)<p>{{ $error }}</p>@endforeach</div>@endif
<p>Choose an approved form revision, then up to 100 apprentices within your Inclusion Team scope. Use the same cycle code to avoid accidental repeats. Existing assignments retain their original revision. Ongoing wellbeing is voluntary: leave its due date blank.</p>
@forelse($forms  as $f)<details class="panel"><summary>{{ $f->title }} · Revision {{ $f->revision }}</summary><form method="post" action="{{ route('portal-questionnaires.assign') }}">@csrf<input type="hidden" name="form_id" value="{{ $f->id }}"><input type="hidden" name="version" value="{{ $f->version }}">
<label>Cycle reference (letters, numbers, hyphens or underscores)<input name="cycle_code" required maxlength="64" placeholder="e.g. induction_2026"></label>
@if($f->kind!=='ongoing')<label>Agreed due date (optional)<input type="date" name="due_date" min="{{ today()->toDateString() }}"></label>@else<p>Voluntary: no due date or overdue reminders.</p>@endif
<fieldset><legend>Apprentices</legend>@foreach($apprentices  as $a)<label><input type="checkbox" name="apprentice_ids[]" value="{{ $a->id }}">{{ $a->first_name }} {{ $a->last_name }} · {{ $a->reference }}</label>@endforeach</fieldset><button class="primary">Create assignments</button></form></details>@empty<p>No published intact forms are available. Ask an authorised form author and a different administrator or CEO to prepare and publish one.</p>@endforelse
@endsection

@extends('layout')
@section('title','Verify employer access')
@section('content')<h1>Verify employer access</h1><p>{{ $apprentice->first_name }} {{ $apprentice->last_name }} · {{ $apprentice->reference }} · {{ $apprentice->employerLabel()??'No linked employer' }}</p>@include('learning.errors')<p>Verify this individual’s identity and authority for this apprentice. This never gives access to everyone at the company. Employer name, mailbox and email domain alone are insufficient.</p><a class="button" href="{{ route('employer-accounts.index') }}">Create / invite employer account</a>
@if($contacts->isNotEmpty())
    <form method="get" class="panel" action="{{ route('employer-grants.index', $apprentice) }}">
        <label>Choose an existing contact or mentor
            <select name="contact" required>
                <option value="">Choose a verified account</option>
                @foreach($contacts as $contact)
                    <option value="{{ $contact->id }}" @selected(request()->integer('contact') === $contact->id)>{{ $contact->name }} · {{ $contact->email }}</option>
                @endforeach
            </select>
        </label>
        <button class="primary">Open account</button>
    </form>
@endif
@if(!request()->filled('contact'))<details class="panel" @if($errors->any() || request()->filled('page')) open @endif><summary>All accounts and access history</summary>@endif
@foreach($users as $user)@php($grant=$grants->get($user->id))<section class="panel"><h2>{{ $user->name }}</h2><p>{{ $user->email }} · {{ $user->email_verified_at?'Mailbox confirmed':'Mailbox not confirmed' }}</p>@if($grant)<p>Grant v{{ $grant->version }} · {{ \App\Support\EmployerActions::valid($grant,$apprentice)?'Current':'Inactive or needs rechecking' }}</p>@endif
@if($user->email_verified_at)<form method="post" action="{{ route('employer-grants.save',$apprentice) }}">@csrf<input type="hidden" name="user_id" value="{{ $user->id }}"><input type="hidden" name="version" value="{{ $grant?->version??0 }}">
@if($apprentice->employerRecord?->active)
    <input type="hidden" name="displayed_employer_id" value="{{ $apprentice->employer_id }}">
    <input type="hidden" name="displayed_employer_version" value="{{ $apprentice->employerRecord->record_version }}">
@endif
@if(request()->integer('contact') === $user->id && $replaceable->contains(fn ($row) => $row['user']->id !== $user->id))
    <label>Replace access for (optional)
        <select name="replace_access">
            <option value="">Keep other workplace access</option>
            @foreach($replaceable as $row)
                @continue($row['user']->id === $user->id)
                @php($value = $row['grant']->id.':'.$row['grant']->version)
                <option value="{{ $value }}" @selected(old('replace_access') === $value)>{{ $row['user']->name }} · {{ $row['user']->email }}</option>
            @endforeach
        </select>
    </label>
@endif
<label for="identity-{{ $user->id }}">Identity and workplace-authority check reference (GTA only)</label><textarea id="identity-{{ $user->id }}" name="identity_reference" maxlength="2000" required>{{ (int) old('user_id') === $user->id ? old('identity_reference') : '' }}</textarea><label class="check"><input type="checkbox" name="confirmed" value="1" required>I checked this individual’s authority for the current employer and this apprentice.</label><button>Verify / reverify named access</button></form>@endif
@if($grant?->active)<form method="post" action="{{ route('employer-grants.revoke',$grant) }}">@csrf<input type="hidden" name="version" value="{{ $grant->version }}"><label for="revoke-{{ $grant->id }}">Reason to revoke</label><textarea id="revoke-{{ $grant->id }}" name="reason" maxlength="2000" required></textarea><button>Revoke access</button></form>@endif</section>@endforeach
{{ $users->links() }}
@if(!request()->filled('contact'))</details>@endif<p>Changing/reverifying access invalidates earlier requests; staff must issue new requests against the current verified grant.</p>@endsection

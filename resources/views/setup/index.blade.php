@extends('layout')
@section('title','Portal setup')
@section('content')
<header class="page-heading"><div><h1>Portal setup</h1><p>Add people once, then assign them to the apprentices they support.</p></div></header>
<details class="panel"><summary>Your administrator account</summary><p>You are signed in as <strong>{{ $owner->name }}</strong> ({{ $owner->email }}) with {{ $owner->role==='ceo'?'CEO':'Administrator' }} access.</p><p>Use your own named account. Martin must provision your first named Admin/CEO account through the deployment's account administration process. The ordinary Staff screen does not create these privileged accounts. Do not keep using shared demonstration credentials.</p><p>A staff role called Manager is not an administrator account. Admin and CEO access allow the setup screens linked below.</p>
    <p>Use one primary role per account. Saving a staff record does not send an invitation.</p>
</details>
<div class="dashboard-grid">
    <section class="panel">
        <h2>Staff accounts</h2>
        <p>{{ $counts['staff'] }} active staff records.</p>
        <div class="toolbar">
            <a class="button primary" href="{{ route('directories.index', 'staff') }}">Manage staff</a>
            <a href="{{ route('onboarding.index') }}">Invite staff</a>
            <a href="{{ route('support.permissions') }}">Support permissions</a>
        </div>
    </section>
    <section class="panel">
        <h2>Apprentice assignments</h2>
        <p>{{ $counts['apprentices'] }} apprentice records. Choose staff from the directory when making an assignment.</p>
        <div class="toolbar">
            <a class="button primary" href="{{ route('apprentices.index') }}">Open apprentices</a>
            <a href="{{ route('assignment-checks.index') }}">Check assignments</a>
        </div>
    </section>
</div>
<details class="panel">
    <summary>Directories and programme settings</summary>
    <p>{{ $counts['employers'] }} active employers · {{ $counts['programmes'] }} active programmes · {{ $counts['groups'] }} active tutor groups.</p>
    <div class="toolbar">
        <a href="{{ route('directories.index', 'employers') }}">Employers</a>
        <a href="{{ route('directories.index', 'programmes') }}">Programmes</a>
        <a href="{{ route('directories.index', 'groups') }}">Tutor groups</a>
    </div>
</details>
@endsection

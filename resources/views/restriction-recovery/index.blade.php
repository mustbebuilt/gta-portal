@extends('layout')
@section('title','Restore restriction checks')
@section('content')
<h1>Check restrictions after a database restore</h1><p>Use a retained-key restriction file to find accounts that should still be blocked. This does not restore a database, reactivate accounts or delete evidence.</p>
@include('learning.errors')
<section class="panel"><h2>Inspect a retained restriction file</h2><form method="post" enctype="multipart/form-data" action="{{ route('restriction-recovery.preview') }}">@csrf<label for="recovery-file">Restriction file (up to1 MB and500 accounts)</label><input id="recovery-file" type="file" name="ledger" required><button>Check identities and current access</button></form><p>The file must authenticate using this application's retained key. A name match alone is insufficient. Later restoration decisions, protected accounts and damaged account proofs need separate review.</p></section>
<section class="panel"><h2>Reviewed recovery requests</h2>@forelse($runs as $item)<p><a href="{{ route('restriction-recovery.show',$item) }}">Request {{ $item->id }}</a> · {{ $item->state }} · {{ $item->created_at->format('d M Y H:i') }}</p>@empty<p>No recovery requests recorded.</p>@endforelse{{ $runs->links() }}</section>
@endsection

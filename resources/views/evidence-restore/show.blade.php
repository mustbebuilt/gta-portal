@extends('layout')
@section('title','Evidence recovery record')
@section('content')
<header class="page-heading"><div><p class="eyebrow">Private evidence operations</p><h1>Evidence recovery record</h1><p>{{ $summary['run_id'] }}</p></div><a class="button" href="{{ route('evidence-restore.index') }}">Back to recovery history</a></header>
<section class="panel"><h2>Recorded result</h2><dl class="details-grid"><div><dt>Recorded position</dt><dd>{{ $summary['state'] }}</dd></div><div><dt>Current manifest/configuration binding</dt><dd>{{ $summary['integrity']==='intact'?'Intact metadata binding':'Needs checks' }}</dd></div><div><dt>Expected objects</dt><dd>{{ $summary['objects']??'Needs checks' }}</dd></div><div><dt>Objects read back during execution</dt><dd>{{ $summary['verified_objects']??'Not recorded' }}</dd></div><div><dt>Recorded finish</dt><dd>{{ $summary['recorded_finished_at']??'Not recorded' }}</dd></div><div><dt>Saved version</dt><dd>{{ $summary['version'] }}</dd></div></dl>
<p>Verification is a recorded observation at the stated time. Opening this page does not recheck file bytes. An operator can explicitly recheck the retained backup and restored objects using the supplied instructions.</p>
@if($summary['state']==='Running')<p class="notice">This may be an interrupted run. Partial files can remain. Do not reset or repeat it; reconcile its protected manifest and technical events privately.</p>@endif
@if($summary['state']==='Needs checks' || $summary['integrity']!=='intact')<p class="notice">Retain all rehearsal files and audit. Investigate the protected operator records without changing originals or application links.</p>@endif
<p>This is a separate recovery rehearsal. It does not prove a complete database/application backup, document authenticity, remote provider privacy, live application recovery or cutover approval. Private filenames, paths, reference notes and file downloads are omitted.</p></section>
<section class="panel"><h2>Technical history</h2><ol>@foreach($events as $event)<li>{{ $event->created_at }} · {{ $event->event }}</li>@endforeach</ol></section>
@endsection

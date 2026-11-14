@extends('layout')
@section('title','Curriculum source checks')
@section('content')
<header class="page-heading"><div><p class="eyebrow">Controlled curriculum</p><h1>Curriculum source checks</h1><p>Current bundled catalogue structure, receipt mappings and blank task-PDF hashes. These technical observations do not approve curriculum, KSB coverage, cohort applicability or funding.</p></div></header>
@foreach($releases as $release)<section class="panel"><h2>{{ $release['programme']['name'] }}</h2><p>{{ $release['programme']['code'] }} · standard version {{ $release['programme']['version'] }} · {{ $release['task_count'] }} task definitions · {{ $release['pdfs_checked'] }} PDFs hash-read.</p><p>{{ $release['technical_ok']?'Loaded release technical checks passed.':'Technical source checks need investigation.' }}</p>@if($release['technical_issues'])<ul>@foreach($release['technical_issues'] as $issue)<li>{{ $issue }}</li>@endforeach</ul>@endif
@foreach($release['notices'] as $notice)<p class="notice"><strong>{{ $notice['topic'] }}</strong><br>{{ $notice['detail'] }}</p>@endforeach
<p>For cohort applicability, open the apprentice’s Task source comparison. Saved historical task versions are retained.</p></section>@endforeach
@endsection

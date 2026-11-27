@extends('layout')
@section('title','Saved work')
@section('content')
<header class="page-heading"><div><h1>Continue saved work</h1><p>Pick up an apprentice setup, review, support plan or delivery plan.</p></div><a class="button" href="{{ route('quick-actions.index') }}">Start another task</a></header>
@include('learning.errors')
<form class="search" method="get"><div><label for="saved-search">Name or apprentice reference</label><input id="saved-search" name="search" value="{{ $search }}" maxlength="100"></div><div><label for="saved-kind">Type of work</label><select id="saved-kind" name="kind">@foreach(\App\Support\SavedPortalWork::KINDS as $key=>$label)@continue($key === 'setup' && !auth()->user()->can('create', \App\Models\Apprentice::class))<option value="{{ $key }}" @selected($kind===$key)>{{ $label }}</option>@endforeach</select></div><button>Find saved work</button><a class="button" href="{{ route('saved-work.index') }}">Clear filters</a></form>
<p>Only work you can currently open is listed. Saving a draft is separate from approval, signatures and delivered training. Administrators can also continue changes awaiting approval to a current delivery plan.</p>
@if($source['limited'])<p class="notice" role="status">This list shows the latest {{ \App\Support\SavedPortalWork::LIMIT }} records per type. Narrow the search to find older work. The counts below show the matching source records.</p>@endif
<dl class="details-grid">@foreach($source['coverage'] as $key=>$counts)<div><dt>{{ \App\Support\SavedPortalWork::KINDS[$key] }}</dt><dd>{{ $counts['matched'] }} matching · {{ $counts['shown'] }} included in this list</dd></div>@endforeach</dl>
<section class="panel">@forelse($items as $item)<article class="work-row"><div><h2>{{ $item['name'] }}</h2><p>{{ $item['reference'] }} · {{ $item['label'] }} · Record {{ $item['id'] }}</p><p>Last saved {{ $item['saved_at']?\Carbon\Carbon::parse($item['saved_at'])->timezone('Europe/London')->format('d M Y H:i'):'Not recorded' }} (UK time).</p></div><div class="toolbar"><a class="button primary" href="{{ $item['url'] }}">Continue at source</a></div></article>@empty<h2>No saved work matches</h2><p>Try clearing the filters or start another task. This list does not include unsaved browser text, submitted work or every kind of portal record.</p>@endforelse
{{ $items->links() }}</section>
@endsection

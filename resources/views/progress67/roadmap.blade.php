@if(!empty($p['roadmap']))
@php
 $roadmap=$p['roadmap'];$journeyQuery=array_merge($report['filters'],['apprentice'=>$p['id'],'metric'=>'all','page'=>1,'section'=>'journey']);
 $journeyExport=$report['demo']?route('progress66.demo-export',['role'=>$report['role'],'format'=>'csv']):route('progress66.export',['format'=>'csv']);
@endphp
<section class="p67-roadmap" aria-labelledby="p67-roadmap-title" data-p67-roadmap>
<header class="p66-section-header"><div><p class="p66-eyebrow">Your story so far</p><h2 id="p67-roadmap-title">Your apprenticeship roadmap</h2><p class="p67-position"><span class="p67-position-dot" aria-hidden="true"></span>{{ $roadmap['position'] }}</p></div><div class="toolbar"><a class="button" href="#p67-events">View journey events</a><a class="button" href="{{ $journeyExport.'?'.http_build_query($journeyQuery) }}">Download journey CSV</a></div></header>
<p class="p66-note">{{ $roadmap['basis'] }}</p>
<ol class="p67-road" aria-label="Apprenticeship stages">
@foreach($roadmap['stops'] as $stop)
<li class="{{ $stop['complete']?'p67-done':'' }} {{ $stop['current']?'p67-current':'' }} {{ $stop['pulse']?'p67-pulse':'' }}" @if($stop['current'])aria-current="step"@endif>
<details><summary><span class="p67-stop" aria-hidden="true">{{ $stop['complete']?'✓':$stop['number'] }}</span><strong>{{ $stop['title'] }}</strong><span class="p66-cell-note">{{ $stop['current']?($stop['pulse']?'You are here':'Recorded achievement'):$stop['status'] }}</span></summary><p>{{ $stop['status'] }}</p><p class="p66-note">Training task completion and formal Gateway/EPA decisions are separate records.</p></details>
</li>
@endforeach
</ol>
<div id="p67-events" class="p67-event-panel"><div class="p66-section-header"><div><h3>Journey events</h3><p class="p66-note">{{ $roadmap['coverage'] }}</p></div><div><label for="p67-category">Show events</label><select id="p67-category" data-p67-category><option value="">All events</option>@foreach(['Programme','Training','Employment','Qualifications','Reviews','Achievements'] as $category)<option>{{ $category }}</option>@endforeach</select></div></div>
<p class="p66-note" data-p67-count role="status">{{ count($roadmap['events']) }} recorded or planned event(s)</p>
<ol class="p67-events">@foreach($roadmap['events'] as $event)<li data-p67-event data-category="{{ $event['category'] }}"><span class="p67-event-dot" aria-hidden="true"></span><time datetime="{{ $event['date'] }}">{{ \Carbon\Carbon::parse($event['date'])->format('d M Y') }}</time><div><strong>{{ $event['title'] }}</strong><p>{{ $event['category'] }} · {{ $event['status'] }}</p></div></li>@endforeach</ol>
<p data-p67-empty @if(count($roadmap['events']))hidden @endif>No matching journey events are recorded. Missing history has not been filled in.</p>
</div>
<details class="p66-more"><summary>Journey event table</summary><div class="p66-table-scroll" tabindex="0" role="region" aria-label="Journey event table"><table><caption>{{ $report['demo']?'Demonstration — fictional data · ':'' }}Non-sensitive journey events · as of {{ $report['source_as_of'] }}</caption><thead><tr><th scope="col">Date</th><th scope="col">Event</th><th scope="col">Category</th><th scope="col">Recorded or planned</th></tr></thead><tbody>@forelse($roadmap['events'] as $event)<tr><td>{{ $event['date'] }}</td><th scope="row">{{ $event['title'] }}</th><td>{{ $event['category'] }}</td><td>{{ $event['status'] }}</td></tr>@empty<tr><td colspan="4">Not recorded</td></tr>@endforelse</tbody></table></div></details>
</section>
@endif

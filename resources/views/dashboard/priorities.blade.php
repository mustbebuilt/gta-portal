<section class="panel priority-heading" id="training-priorities" aria-labelledby="training-priorities-title">
<h2 id="training-priorities-title">{{ auth()->user()->role==='apprentice'?'Your next training steps':'Work today' }}</h2>

<div class="priority-summary" aria-label="Training work counts"><span><strong>{{ $priorities['counts']['overdue'] }}</strong> overdue</span><span><strong>{{ $priorities['counts']['today'] }}</strong> due today</span><span><strong>{{ $priorities['counts']['checks'] }}</strong> checks / Tutor follow-up</span></div>
@include('dashboard.work-filters83')
<p class="muted">{{ $priorityList->total() }} items in this view @if($priorityList->hasPages()) · page {{ $priorityList->currentPage() }} of {{ $priorityList->lastPage() }}@endif</p>
<ol class="priority-list">
@forelse($priorityList as $item)
<li class="priority-row" data-priority-row="{{ $item['key'] }}"><div><span class="status {{ $item['bucket']==='overdue'?'red':'neutral' }}">{{ ['overdue'=>'Overdue','today'=>'Due today','checks'=>'Needs checking'][$item['bucket']] }} · {{ ['marking'=>'Marking','task'=>'Training task','action'=>'SMART action','review'=>'Progress review','register'=>'Register'][$item['kind']] }}</span><h3>{{ \Illuminate\Support\Str::limit($item['title'],140) }}</h3><p>{{ $item['apprentice'] }} · {{ $item['reference'] }}<br>{{ $item['owner'] }} · Due {{ $item['date']??'Needs checking' }}</p>@if($item['reason'])<p class="recorded-answer">{{ $item['reason'] }}</p>@endif</div><a @class(['button','primary'=>$loop->first]) href="{{ $item['url'] }}">{{$loop->first?'Start next item ·':'Open'}} {{ ['action'=>'action','task'=>'task','marking'=>'task','review'=>'review','register'=>'register'][$item['kind']] }}<span class="sr-only">: {{ $item['reference'] }} · {{ $item['title'] }}</span></a></li>
@empty
<li><p>No matching work in this view. Other areas may still need attention.</p></li>
@endforelse
</ol>
@if($priorityList->hasPages())<nav class="pagination" aria-label="Training work pages">@if($priorityList->previousPageUrl())<a class="button" href="{{ $priorityList->previousPageUrl() }}#training-priorities">Previous work</a>@endif @if($priorityList->nextPageUrl())<a class="button" href="{{ $priorityList->nextPageUrl() }}#training-priorities">Next work</a>@endif</nav>@endif
<details><summary>About this list</summary><p>Due training, marking, actions, reviews and ended registers in your permitted scope. Linked actions appear once with their source task. Future work, support and qualification checks remain in their own areas. Missing information stays a check; opening an item does not complete it.</p></details>
</section>

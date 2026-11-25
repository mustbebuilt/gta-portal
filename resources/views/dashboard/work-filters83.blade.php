@php($activeWorkFilters = request()->filled('group') || request()->filled('apprentice') || $priorityFilter!=='all')
<details class="panel" @if($activeWorkFilters || $errors->any()) open @endif><summary>Filter work{{$activeWorkFilters?' · filters applied':''}}</summary>
<form method="get" action="{{route('dashboard.index')}}" class="search">
@if(auth()->user()->role!=='apprentice')
<div><label for="dashboard-group">Group</label><select id="dashboard-group" name="group"><option value="">All visible groups</option>@foreach($groups as $g)<option value="{{$g->id}}" @selected((string)request('group')===(string)$g->id)>{{$g->name}}</option>@endforeach</select></div>
<div><label for="dashboard-apprentice">Apprentice</label><select id="dashboard-apprentice" name="apprentice"><option value="">All in selected group</option>@foreach($visible as $a)<option value="{{$a->id}}" @selected((string)request('apprentice')===(string)$a->id)>{{$a->first_name}} {{$a->last_name}}</option>@endforeach</select></div>
@endif
<div><label for="priority-filter">Show work</label><select id="priority-filter" name="priority">@foreach(['all'=>'All listed work','overdue'=>'Overdue','today'=>'Due today','checks'=>'Needs checking'] as $value=>$label)<option value="{{$value}}" @selected($priorityFilter===$value)>{{$label}}</option>@endforeach</select></div>
<button>Apply filters</button>@if($activeWorkFilters)<a class="button" href="{{route('dashboard.index')}}">Clear</a>@endif
</form></details>

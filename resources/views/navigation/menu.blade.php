@php($menu=\App\Support\PortalNavigation::build(auth()->user(),request()))
<button type="button" id="navigation-toggle" class="navigation-toggle" aria-controls="portal-navigation" aria-expanded="true" hidden>Hide menu</button>
<nav id="portal-navigation" class="grouped-navigation" aria-label="Main navigation">
<div class="navigation-shortcuts">@foreach($menu['primary'] as $link)<a href="{{ $link['href'] }}" @if($link['current'])aria-current="page"@endif>{{ $link['label'] }}</a>@endforeach</div>
@foreach($menu['groups'] as $group)<details class="navigation-group" data-navigation-group="{{ $group['id'] }}" @if($group['open'])open @endif><summary>{{ $group['label'] }}</summary><div class="navigation-group-links">@foreach($group['links'] as $link)<a href="{{ $link['href'] }}" @if($link['current'])aria-current="page"@endif>{{ $link['label'] }}</a>@endforeach</div></details>@endforeach
@if($menu['help'])<a class="navigation-help" href="{{ $menu['help']['href'] }}" @if($menu['help']['current'])aria-current="page"@endif>{{ $menu['help']['label'] }}</a>@endif
</nav>

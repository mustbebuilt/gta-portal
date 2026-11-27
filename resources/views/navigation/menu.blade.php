@php($menu = \App\Support\PortalNavigation::forDisplay(auth()->user(), request()))
<nav id="portal-navigation" class="grouped-navigation" aria-label="Main navigation">
    <div class="navigation-shortcuts">
        @foreach($menu['daily'] as $link)
            <a href="{{ $link['href'] }}" @if($link['href'] === $menu['currentUrl']) aria-current="page" @endif>{{ $link['label'] }}</a>
        @endforeach
    </div>
    @if($menu['sections'])
        <details class="navigation-group navigation-more">
            <summary><span>More tools
                @if($menu['currentTool'])<small>{{ $menu['currentTool'] }}</small>@endif
            </span></summary>
            @foreach($menu['sections'] as $group)
                <section class="navigation-section" data-navigation-group="{{ $group['id'] }}" aria-labelledby="nav-group-{{ $group['id'] }}">
                    <h2 id="nav-group-{{ $group['id'] }}">{{ $group['label'] }}</h2>
                    <div class="navigation-group-links">
                        @foreach($group['links'] as $link)
                            <a href="{{ $link['href'] }}" @if($link['href'] === $menu['currentUrl']) aria-current="page" @endif>{{ $link['label'] }}</a>
                        @endforeach
                    </div>
                </section>
            @endforeach
        </details>
    @endif
    @if($menu['help'])
        <a class="navigation-help" href="{{ $menu['help']['href'] }}" @if($menu['help']['href'] === $menu['currentUrl']) aria-current="page" @endif>{{ $menu['help']['label'] }}</a>
    @endif
</nav>

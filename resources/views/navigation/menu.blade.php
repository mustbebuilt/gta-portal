@php($menu = \App\Support\FriendlyTheme117::menu(auth()->user(), request()))
<nav id="portal-navigation" class="grouped-navigation" aria-label="Main navigation">
    <div class="navigation-shortcuts">
        @foreach($menu['daily'] as $link)
            <a href="{{ $link['href'] }}" aria-label="{{ $link['label'] }}" @if($link['href'] === $menu['currentUrl']) aria-current="page" @endif><span class="theme117-nav-icon">@include('navigation.theme117-icon',['icon'=>\App\Support\FriendlyTheme117::icon($link['label'])])</span><span>{{ \App\Support\FriendlyTheme117::label($link['label']) }}</span></a>
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

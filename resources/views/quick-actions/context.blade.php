@if(isset($apprentice) && auth()->check() && \App\Support\PortalActions::hasPerson(auth()->user(),$apprentice))
<nav class="context-actions" aria-label="Apprentice navigation"><span>{{ $apprentice->reference }}</span><a href="{{ route('quick-actions.person',$apprentice) }}">All available actions for this apprentice</a></nav>
@endif

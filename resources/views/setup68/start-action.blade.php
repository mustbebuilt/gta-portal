@if(\App\Models\ApprenticeSetupDraft68::whereIn('state', ['Draft', 'Ready'])->exists())
    <a class="button primary" href="{{ route('setup68.index') }}">Add apprentice or continue setup</a>
@else
    <form method="post" action="{{ route('setup68.create') }}">
        @csrf
        <button class="primary">Add apprentice</button>
    </form>
@endif

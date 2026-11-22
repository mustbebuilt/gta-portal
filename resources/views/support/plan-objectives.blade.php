@if($plan->status === 'Active')
<section class="panel" id="support-objectives">
    <h2>Support objectives</h2>
    <ul>
        @forelse($plan->activeObjectives() as $objective)
        <li>{{ $objective['title'] }}</li>
        @empty
        <li>No objectives recorded for this approved plan version.</li>
        @endforelse
    </ul>
    <form method="post" action="{{ route('support.objectives.store', $plan) }}">
        @csrf
        <input type="hidden" name="version" value="{{ $plan->version }}">
        <label>Agreed objective <input name="objective" maxlength="250" value="{{ old('objective') }}" required></label>
        <button>Save objective</button>
    </form>
</section>
@endif

<section class="panel" aria-labelledby="employer-search-title">
    <h2 id="employer-search-title">Find an employer</h2>
    <form method="get" action="{{ route('setup68.show', [$draft, 5]) }}" class="search">
        <div>
            <label for="employer-search">Employer name or reference</label>
            <input id="employer-search" name="employer_search" value="{{ $employerSearch }}" maxlength="100" required>
        </div>
        <button>Search employers</button>
    </form>
    @if($employerSearch !== '' && !$employerMatchCount)
        <p>No active employers match. You can add a new employer below.</p>
    @elseif($employerSearch !== '' && $employerMatchCount === 30)
        <p>Showing up to 30 matches. Refine the search if you cannot find the employer.</p>
    @endif
</section>

@if ($paginator->hasPages())
    <nav class="pagination" role="navigation" aria-label="Pagination">
        @if ($paginator->onFirstPage())
            <span class="button muted" aria-disabled="true">Previous</span>
        @else
            <a class="button" href="{{ $paginator->previousPageUrl() }}" rel="prev">Previous</a>
        @endif

        @if ($paginator->hasMorePages())
            <a class="button" href="{{ $paginator->nextPageUrl() }}" rel="next">Next</a>
        @else
            <span class="button muted" aria-disabled="true">Next</span>
        @endif
    </nav>
@endif

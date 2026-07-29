@if ($paginator->hasPages())
    <nav class="pager" role="navigation" aria-label="Pagination">
        @if ($paginator->onFirstPage())
            <span class="pager-link disabled" aria-disabled="true">&laquo; Prev</span>
        @else
            <a class="pager-link" href="{{ $paginator->previousPageUrl() }}" rel="prev">&laquo; Prev</a>
        @endif

        @foreach ($elements as $element)
            @if (is_string($element))
                <span class="pager-gap">{{ $element }}</span>
            @endif

            @if (is_array($element))
                @foreach ($element as $page => $url)
                    @if ($page == $paginator->currentPage())
                        <span class="pager-link active" aria-current="page">{{ $page }}</span>
                    @else
                        <a class="pager-link" href="{{ $url }}">{{ $page }}</a>
                    @endif
                @endforeach
            @endif
        @endforeach

        @if ($paginator->hasMorePages())
            <a class="pager-link" href="{{ $paginator->nextPageUrl() }}" rel="next">Next &raquo;</a>
        @else
            <span class="pager-link disabled" aria-disabled="true">Next &raquo;</span>
        @endif
    </nav>

    <p class="pager-summary">
        Showing {{ $paginator->firstItem() }}–{{ $paginator->lastItem() }} of {{ $paginator->total() }}
    </p>
@endif

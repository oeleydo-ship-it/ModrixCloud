{{-- Page links for every paginated list: small arrows, page numbers and a count. --}}
@if ($paginator->hasPages())
    <nav class="pager" aria-label="Pages">
        @if ($paginator->onFirstPage())
            <span class="pager-step" aria-disabled="true">&lsaquo; Previous</span>
        @else
            <a class="pager-step" href="{{ $paginator->previousPageUrl() }}" rel="prev">&lsaquo; Previous</a>
        @endif

        <span class="pager-pages">
            @foreach ($elements as $element)
                @if (is_string($element))
                    <span class="pager-gap">{{ $element }}</span>
                @endif

                @if (is_array($element))
                    @foreach ($element as $page => $url)
                        @if ($page == $paginator->currentPage())
                            <span class="pager-page" aria-current="page">{{ $page }}</span>
                        @else
                            <a class="pager-page" href="{{ $url }}">{{ $page }}</a>
                        @endif
                    @endforeach
                @endif
            @endforeach
        </span>

        @if ($paginator->hasMorePages())
            <a class="pager-step" href="{{ $paginator->nextPageUrl() }}" rel="next">Next &rsaquo;</a>
        @else
            <span class="pager-step" aria-disabled="true">Next &rsaquo;</span>
        @endif

        <span class="pager-count">Showing {{ $paginator->firstItem() }}–{{ $paginator->lastItem() }} of {{ $paginator->total() }}</span>
    </nav>
@endif

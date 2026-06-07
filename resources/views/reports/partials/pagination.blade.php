@if ($paginator->hasPages())
    <div class="pagination-wrap">
        {{ $paginator->links() }}
    </div>
@endif

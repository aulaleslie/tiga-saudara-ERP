<div class="btn-group dropleft">
    <button type="button" class="btn btn-ghost-primary dropdown rounded" data-toggle="dropdown" aria-expanded="false">
        <i class="bi bi-three-dots-vertical"></i>
    </button>
    <div class="dropdown-menu">
        @can('purchase.create')
            @if($data->status === 'RECEIVED')
                <a href="{{ route('purchase-payments.index', $data->id) }}" class="dropdown-item">
                    <i class="bi bi-cash-coin mr-2 text-warning" style="line-height: 1;"></i> Show Payments
                </a>
            @endif
        @endcan

        @can('purchase.create')
            @if($data->status === 'RECEIVED')
                @if($data->due_amount > 0)
                    <a href="{{ route('purchase-payments.create', $data->id) }}" class="dropdown-item">
                        <i class="bi bi-plus-circle-dotted mr-2 text-success" style="line-height: 1;"></i> Add Payment
                    </a>
                @endif
            @endif
        @endcan

        @can('purchase.edit')
            @if($data->status === 'DRAFTED')
                <a href="{{ route('purchases.edit', $data->id) }}" class="dropdown-item">
                    <i class="bi bi-pencil mr-2 text-primary" style="line-height: 1;"></i> Edit
                </a>
            @endif
        @endcan

        @can('purchase.view')
            <a href="{{ route('purchases.show', $data->id) }}" class="dropdown-item">
                <i class="bi bi-eye mr-2 text-info" style="line-height: 1;"></i> Details
            </a>
        @endcan

        @can('purchase.delete')
            @if($data->status === 'DRAFTED')
                <button id="delete" class="dropdown-item" onclick="
                    event.preventDefault();
                    if (confirm('Anda Yakin untuk Menghapus? Data akan Terhapus Permanen!')) {
                    document.getElementById('destroy{{ $data->id }}').submit()
                    }">
                    <i class="bi bi-trash mr-2 text-danger" style="line-height: 1;"></i> Delete
                    <form id="destroy{{ $data->id }}" class="d-none" action="{{ route('purchases.destroy', $data->id) }}" method="POST">
                        @csrf
                        @method('delete')
                    </form>
                </button>
            @endif
        @endcan
    </div>
</div>

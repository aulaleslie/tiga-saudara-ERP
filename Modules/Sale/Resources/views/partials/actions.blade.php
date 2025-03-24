<div class="btn-group dropleft">
    <button type="button" class="btn btn-ghost-primary dropdown rounded" data-toggle="dropdown" aria-expanded="false">
        <i class="bi bi-three-dots-vertical"></i>
    </button>
    <div class="dropdown-menu">
        <a target="_blank" href="{{ route('sales.pos.pdf', $data->id) }}" class="dropdown-item">
            <i class="bi bi-file-earmark-pdf mr-2 text-success" style="line-height: 1;"></i> POS Invoice
        </a>
        @can('access_sale_payments')
            <a href="{{ route('sale-payments.index', $data->id) }}" class="dropdown-item">
                <i class="bi bi-cash-coin mr-2 text-warning" style="line-height: 1;"></i> Show Payments
            </a>
        @endcan
        @can('access_sale_payments')
            @if($data->due_amount > 0)
            <a href="{{ route('sale-payments.create', $data->id) }}" class="dropdown-item">
                <i class="bi bi-plus-circle-dotted mr-2 text-success" style="line-height: 1;"></i> Add Payment
            </a>
            @endif
        @endcan
        @can('edit_sales')
            <a href="{{ route('sales.edit', $data->id) }}" class="dropdown-item">
                <i class="bi bi-pencil mr-2 text-primary" style="line-height: 1;"></i> Edit
            </a>
        @endcan
        @can('show_sales')
            <a href="{{ route('sales.show', $data->id) }}" class="dropdown-item">
                <i class="bi bi-eye mr-2 text-info" style="line-height: 1;"></i> Details
            </a>
        @endcan
        {{-- New Actions for Status Updates --}}
        @if ($data->status === 'DRAFTED')
            <form method="POST" action="{{ route('sales.updateStatus', $data->id) }}">
                @csrf
                @method('PATCH')
                <input type="hidden" name="status" value="WAITING_APPROVAL">
                <button type="submit" class="dropdown-item text-warning">
                    <i class="bi bi-send mr-2"></i> Kirim untuk Persetujuan
                </button>
            </form>
        @endif

        @if ($data->status === 'WAITING_APPROVAL')
            <form method="POST" action="{{ route('sales.updateStatus', $data->id) }}">
                @csrf
                @method('PATCH')
                <input type="hidden" name="status" value="APPROVED">
                <button type="submit" class="dropdown-item text-success">
                    <i class="bi bi-check-circle mr-2"></i> Setuju
                </button>
            </form>
            <form method="POST" action="{{ route('sales.updateStatus', $data->id) }}">
                @csrf
                @method('PATCH')
                <input type="hidden" name="status" value="REJECTED">
                <button type="submit" class="dropdown-item text-danger">
                    <i class="bi bi-x-circle mr-2"></i> Tolak
                </button>
            </form>
        @endif
        @can('delete_sales')
            <button id="delete" class="dropdown-item" onclick="
                event.preventDefault();
                if (confirm('Anda Yakin untuk Menghapus? Data akan Terhapus Permanen!')) {
                document.getElementById('destroy{{ $data->id }}').submit()
                }">
                <i class="bi bi-trash mr-2 text-danger" style="line-height: 1;"></i> Delete
                <form id="destroy{{ $data->id }}" class="d-none" action="{{ route('sales.destroy', $data->id) }}" method="POST">
                    @csrf
                    @method('delete')
                </form>
            </button>
        @endcan
    </div>
</div>

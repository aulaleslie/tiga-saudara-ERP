@php $approvalStatus = strtolower($data->approval_status ?? ''); @endphp
<div class="btn-group dropleft">
    <button type="button" class="btn btn-ghost-primary dropdown-toggle" data-toggle="dropdown" aria-expanded="false">
        <i class="bi bi-three-dots-vertical"></i>
    </button>
    <div class="dropdown-menu dropdown-menu-right shadow-sm">
        @can('sales.edit')
            @if(in_array($approvalStatus, ['pending', 'draft']))
                <a href="{{ route('sale-returns.edit', $data->id) }}" class="dropdown-item d-flex align-items-center">
                    <i class="bi bi-pencil text-primary me-2"></i> <span>Edit</span>
                </a>
            @endif
        @endcan

        @can('sales.show')
            <a href="{{ route('sale-returns.show', $data->id) }}" class="dropdown-item d-flex align-items-center">
                <i class="bi bi-eye text-info me-2"></i> <span>Detail</span>
            </a>
        @endcan

        @can('salePayments.show')
            <a href="{{ route('sale-return-payments.index', $data->id) }}" class="dropdown-item d-flex align-items-center">
                <i class="bi bi-cash-coin text-warning me-2"></i> <span>Pembayaran</span>
            </a>
        @endcan

        @can('salePayments.create')
            @if($data->due_amount > 0)
                <a href="{{ route('sale-return-payments.create', $data->id) }}" class="dropdown-item d-flex align-items-center">
                    <i class="bi bi-plus-circle text-success me-2"></i> <span>Tambah Pembayaran</span>
                </a>
            @endif
        @endcan

        @can('sales.delete')
            @if(in_array($approvalStatus, ['pending', 'draft']))
                <button id="delete" type="button" class="dropdown-item d-flex align-items-center" onclick="
                    event.preventDefault();
                    if (confirm('Anda yakin ingin menghapus retur ini?')) {
                        document.getElementById('destroy{{ $data->id }}').submit()
                    }">
                    <i class="bi bi-trash text-danger me-2"></i> <span>Hapus</span>
                </button>
                <form id="destroy{{ $data->id }}" class="d-none" action="{{ route('sale-returns.destroy', $data->id) }}" method="POST">
                    @csrf
                    @method('delete')
                </form>
            @endif
        @endcan
    </div>
</div>

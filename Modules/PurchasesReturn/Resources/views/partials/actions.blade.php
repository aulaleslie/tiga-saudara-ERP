<div class="btn-group dropleft">
    <button type="button" class="btn btn-ghost-primary dropdown-toggle" data-toggle="dropdown" aria-expanded="false">
        <i class="bi bi-three-dots-vertical"></i>
    </button>
    <div class="dropdown-menu dropdown-menu-right shadow-sm">
        @can('purchaseReturnPayments.show')
            <a href="{{ route('purchase-return-payments.index', $data->id) }}" class="dropdown-item d-flex align-items-center">
                <i class="bi bi-cash-coin text-warning me-2"></i> <span>Pembayaran</span>
            </a>
        @endcan

        @can('purchaseReturnPayments.create')
            @if($data->approval_status === 'approved' && $data->due_amount > 0)
                <a href="{{ route('purchase-return-payments.create', $data->id) }}" class="dropdown-item d-flex align-items-center">
                    <i class="bi bi-plus-circle-dotted text-success me-2"></i> <span>Tambah Pembayaran</span>
                </a>
            @endif
        @endcan

        @can('purchaseReturns.edit')
            @if($data->approval_status === 'pending')
                <a href="{{ route('purchase-returns.edit', $data->id) }}" class="dropdown-item d-flex align-items-center">
                    <i class="bi bi-pencil text-primary me-2"></i> <span>Edit</span>
                </a>
            @endif
        @endcan

        @can('purchaseReturns.edit')
            @if($data->approval_status === 'pending')
                <form method="POST" action="{{ route('purchase-returns.approve', $data->id) }}" class="m-0">
                    @csrf
                    <button type="submit" class="dropdown-item d-flex align-items-center border-0 bg-transparent px-0" onclick="return confirm('Setujui retur pembelian ini?')">
                        <i class="bi bi-check2-circle text-success me-2"></i> <span>Setujui</span>
                    </button>
                </form>
                <a href="#" class="dropdown-item d-flex align-items-center" onclick="event.preventDefault(); purchaseReturnReject{{ $data->id }}();">
                    <i class="bi bi-x-circle text-danger me-2"></i> <span>Tolak</span>
                </a>
                <form id="reject-form-{{ $data->id }}" method="POST" action="{{ route('purchase-returns.reject', $data->id) }}" class="d-none">
                    @csrf
                    <input type="hidden" name="reason" value="">
                </form>
                <script>
                    function purchaseReturnReject{{ $data->id }}() {
                        const reason = prompt('Masukkan alasan penolakan (opsional):');
                        if (reason !== null) {
                            const form = document.getElementById('reject-form-{{ $data->id }}');
                            form.querySelector('input[name="reason"]').value = reason;
                            form.submit();
                        }
                    }
                </script>
            @endif
        @endcan

        @can('purchaseReturns.show')
            <a href="{{ route('purchase-returns.show', $data->id) }}" class="dropdown-item d-flex align-items-center">
                <i class="bi bi-eye text-info me-2"></i> <span>Detail</span>
            </a>
        @endcan

        @can('purchaseReturns.edit')
            @if($data->approval_status === 'approved')
                <a href="{{ route('purchase-returns.settlement', $data->id) }}" class="dropdown-item d-flex align-items-center">
                    <i class="bi bi-arrow-repeat text-primary me-2"></i> <span>Metode Penyelesaian</span>
                </a>
            @endif
        @endcan

        @can('purchaseReturns.delete')
            @if($data->approval_status === 'pending')
                <button id="delete" type="button" class="dropdown-item d-flex align-items-center" onclick="
                    event.preventDefault();
                    if (confirm('Anda Yakin untuk Menghapus? Data akan Terhapus Permanen!')) {
                        document.getElementById('destroy{{ $data->id }}').submit()
                    }">
                    <i class="bi bi-trash text-danger me-2"></i> <span>Hapus</span>
                </button>
                <form id="destroy{{ $data->id }}" class="d-none" action="{{ route('purchase-returns.destroy', $data->id) }}" method="POST">
                    @csrf
                    @method('delete')
                </form>
            @endif
        @endcan
    </div>
</div>

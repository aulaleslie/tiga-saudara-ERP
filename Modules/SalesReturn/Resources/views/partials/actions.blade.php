@php
    $approvalStatus = strtolower($data->approval_status ?? '');
    $status = strtolower($data->status ?? '');
@endphp
<div class="btn-group dropleft">
    <button type="button" class="btn btn-ghost-primary dropdown-toggle" data-toggle="dropdown" aria-expanded="false">
        <i class="bi bi-three-dots-vertical"></i>
    </button>
    <div class="dropdown-menu dropdown-menu-right shadow-sm">
        @can('saleReturns.edit')
            @if(in_array($approvalStatus, ['pending', 'draft']))
                <a href="{{ route('sale-returns.edit', $data->id) }}" class="dropdown-item d-flex align-items-center">
                    <i class="bi bi-pencil text-primary me-2"></i> <span>Edit</span>
                </a>
            @endif
        @endcan

        @can('saleReturns.approve')
            @if($approvalStatus === 'pending')
                <form method="POST" action="{{ route('sale-returns.approve', $data->id) }}" class="m-0">
                    @csrf
                    <button type="submit" class="dropdown-item d-flex align-items-center border-0 bg-transparent px-0" onclick="return confirm('Setujui retur penjualan ini?')">
                        <i class="bi bi-check2-circle text-success me-2"></i> <span>Setujui</span>
                    </button>
                </form>

                <a href="#" class="dropdown-item d-flex align-items-center" onclick="event.preventDefault(); saleReturnReject{{ $data->id }}();">
                    <i class="bi bi-x-circle text-danger me-2"></i> <span>Tolak</span>
                </a>
                <form id="sale-return-reject-{{ $data->id }}" method="POST" action="{{ route('sale-returns.reject', $data->id) }}" class="d-none">
                    @csrf
                    <input type="hidden" name="reason" value="">
                </form>
                <script>
                    function saleReturnReject{{ $data->id }}() {
                        const reason = prompt('Masukkan alasan penolakan (opsional):');
                        if (reason !== null) {
                            const form = document.getElementById('sale-return-reject-{{ $data->id }}');
                            form.querySelector('input[name="reason"]').value = reason;
                            form.submit();
                        }
                    }
                </script>
            @endif
        @endcan

        @can('saleReturns.show')
            <a href="{{ route('sale-returns.show', $data->id) }}" class="dropdown-item d-flex align-items-center">
                <i class="bi bi-eye text-info me-2"></i> <span>Detail</span>
            </a>
        @endcan

        @can('saleReturns.edit')
            @if($approvalStatus === 'approved')
                <a href="{{ route('sale-returns.settlement', $data->id) }}" class="dropdown-item d-flex align-items-center">
                    <i class="bi bi-clipboard-check text-success me-2"></i> <span>Penyelesaian</span>
                </a>
            @endif
        @endcan

        @can('saleReturns.receive')
            @if($status === 'awaiting receiving')
                <form method="POST" action="{{ route('sale-returns.receive', $data->id) }}" class="m-0">
                    @csrf
                    <button type="submit" class="dropdown-item d-flex align-items-center border-0 bg-transparent px-0" onclick="return confirm('Terima barang retur ini ke stok?')">
                        <i class="bi bi-box-arrow-in-down text-primary me-2"></i> <span>Terima Barang</span>
                    </button>
                </form>
            @endif
        @endcan

        @can('salePayments.show')
            <a href="{{ route('sale-return-payments.index', $data->id) }}" class="dropdown-item d-flex align-items-center">
                <i class="bi bi-cash-coin text-warning me-2"></i> <span>Pembayaran</span>
            </a>
        @endcan

        @can('saleReturns.delete')
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

<div class="btn-group dropleft">
    <button type="button" class="btn btn-ghost-primary dropdown rounded" data-toggle="dropdown" aria-expanded="false">
        <i class="bi bi-three-dots-vertical"></i>
    </button>
    <div class="dropdown-menu">
        @can('purchaseReturnPayments.show')
            <a href="{{ route('purchase-return-payments.index', $data->id) }}" class="dropdown-item">
                <i class="bi bi-cash-coin mr-2 text-warning" style="line-height: 1;"></i> Show Payments
            </a>
        @endcan

        @can('purchaseReturnPayments.create')
            @if($data->approval_status === 'approved' && $data->due_amount > 0)
                <a href="{{ route('purchase-return-payments.create', $data->id) }}" class="dropdown-item">
                    <i class="bi bi-plus-circle-dotted mr-2 text-success" style="line-height: 1;"></i> Add Payment
                </a>
            @endif
        @endcan

        @can('purchaseReturns.edit')
            @if($data->approval_status === 'pending')
                <a href="{{ route('purchase-returns.edit', $data->id) }}" class="dropdown-item">
                    <i class="bi bi-pencil mr-2 text-primary" style="line-height: 1;"></i> Edit
                </a>
            @endif
        @endcan

        @can('purchaseReturns.edit')
            @if($data->approval_status === 'pending')
                <form method="POST" action="{{ route('purchase-returns.approve', $data->id) }}">
                    @csrf
                    <button type="submit" class="dropdown-item" onclick="return confirm('Setujui retur pembelian ini?')">
                        <i class="bi bi-check2-circle mr-2 text-success" style="line-height: 1;"></i> Approve
                    </button>
                </form>
                <a href="#" class="dropdown-item" onclick="event.preventDefault(); purchaseReturnReject{{ $data->id }}();">
                    <i class="bi bi-x-circle mr-2 text-danger" style="line-height: 1;"></i> Reject
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
            <a href="{{ route('purchase-returns.show', $data->id) }}" class="dropdown-item">
                <i class="bi bi-eye mr-2 text-info" style="line-height: 1;"></i> Details
            </a>
        @endcan

        @can('purchaseReturns.delete')
            @if($data->approval_status === 'pending')
                <button id="delete" class="dropdown-item" onclick="
                    event.preventDefault();
                    if (confirm('Anda Yakin untuk Menghapus? Data akan Terhapus Permanen!')) {
                        document.getElementById('destroy{{ $data->id }}').submit()
                    }">
                    <i class="bi bi-trash mr-2 text-danger" style="line-height: 1;"></i> Delete
                    <form id="destroy{{ $data->id }}" class="d-none" action="{{ route('purchase-returns.destroy', $data->id) }}" method="POST">
                        @csrf
                        @method('delete')
                    </form>
                </button>
            @endif
        @endcan
    </div>
</div>

<div class="btn-group dropup" wire:key="global-payment-action-{{ $data->id }}-{{ $tableRefreshId ?? 0 }}" data-global-payment-action>
    <button type="button" class="btn btn-sm btn-outline-secondary dropdown-toggle" data-toggle="dropdown" aria-haspopup="true" aria-expanded="false">
        <i class="bi bi-three-dots"></i>
    </button>
    <div class="dropdown-menu dropdown-menu-right">
        @can('purchasePayments.global.access')
            <a href="{{ route('purchases.global-payments.show', $data->id) }}" class="dropdown-item">
                <i class="bi bi-eye mr-2 text-info" style="line-height: 1;"></i> Detail
            </a>

            <a href="{{ route('purchases.global-payments.history', $data->id) }}" class="dropdown-item">
                <i class="bi bi-clock-history mr-2 text-primary" style="line-height: 1;"></i> Riwayat Pembayaran
            </a>

            @can('purchasePayments.create')
                @if($data->live_due_amount > 0)
                    <a href="{{ route('purchases.global-payments.create', ['supplier' => $data->supplier_id, 'purchase_id' => $data->id]) }}" class="dropdown-item">
                        <i class="bi bi-cash-stack mr-2 text-success" style="line-height: 1;"></i> Tambah Pembayaran
                    </a>
                @endif
            @endcan
        @endcan
    </div>
</div>

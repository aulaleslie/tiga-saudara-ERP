@can('purchaseReturns.approval')
    @if($approvalStatus === 'pending')
        <div class="modal fade" id="approvePurchaseReturnModal" tabindex="-1" aria-labelledby="approvePurchaseReturnModalLabel" aria-hidden="true">
            <div class="modal-dialog">
                <div class="modal-content">
                    <form method="POST" action="{{ route('purchase-returns.approve', $purchase_return) }}">
                        @csrf
                        <div class="modal-header">
                            <h5 class="modal-title" id="approvePurchaseReturnModalLabel">Konfirmasi Persetujuan</h5>
                            <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                        </div>
                        <div class="modal-body">
                            Apakah Anda yakin ingin menyetujui retur pembelian ini?
                        </div>
                        <div class="modal-footer">
                            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Batal</button>
                            <button type="submit" class="btn btn-success">Setujui</button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    @endif
@endcan

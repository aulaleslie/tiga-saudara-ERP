@can('purchaseReturns.dispatchApproval')
    @if($dispatchStatus === 'pending_approval')
        <div class="modal fade" id="approveDispatchModal" tabindex="-1" aria-labelledby="approveDispatchModalLabel" aria-hidden="true">
            <div class="modal-dialog modal-lg">
                <div class="modal-content">
                    <form method="POST" action="{{ route('purchase-returns.dispatch-approve', $purchase_return) }}">
                        @csrf
                        <div class="modal-header">
                            <h5 class="modal-title" id="approveDispatchModalLabel">Konfirmasi Persetujuan Dispatch</h5>
                            <button type="button" class="close" data-dismiss="modal" data-bs-dismiss="modal" aria-label="Close">
                                <span aria-hidden="true">&times;</span>
                            </button>
                        </div>
                        <div class="modal-body">
                            <p class="mb-3">Setujui pengiriman retur ini?</p>
                            @include('purchasesreturn::partials.dispatch-info')
                        </div>
                        <div class="modal-footer">
                            <button type="button" class="btn btn-secondary" data-dismiss="modal" data-bs-dismiss="modal">Batal</button>
                            <button type="submit" class="btn btn-success">Setujui Pengiriman</button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    @endif
@endcan

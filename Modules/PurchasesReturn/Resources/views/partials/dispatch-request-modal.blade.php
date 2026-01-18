@can('purchaseReturns.dispatchRequest')
    <div class="modal fade" id="dispatchRequestModal" tabindex="-1" aria-labelledby="dispatchRequestModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <form action="{{ route('purchase-returns.dispatch-request', $purchase_return) }}" method="POST" enctype="multipart/form-data" id="dispatch-request-form">
                    @csrf
                    <div class="modal-header">
                        <h5 class="modal-title" id="dispatchRequestModalLabel">Ajukan Dispatch Retur</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                    </div>
                    <div class="modal-body">
                        <div class="row g-3">
                            <div class="col-md-6">
                                <label class="form-label" for="return_shipping_amount">Ongkir</label>
                                <input type="text" name="return_shipping_amount" id="return_shipping_amount" class="form-control" inputmode="decimal" value="{{ old('return_shipping_amount', $purchase_return->return_shipping_amount ?? 0) }}">
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Lampiran <span class="text-danger">*</span></label>
                                @php $oldAttachments = old('return_awb_attachments', []); @endphp
                                @if(is_array($oldAttachments) && count($oldAttachments))
                                    @foreach($oldAttachments as $temp)
                                        <input type="hidden" name="return_awb_attachments[]" value="{{ $temp }}">
                                    @endforeach
                                @endif
                                <div class="dropzone d-flex flex-wrap align-items-center justify-content-center" id="return-dispatch-attachments-dropzone">
                                    <div class="dz-message" data-dz-message>
                                        <i class="bi bi-cloud-arrow-up"></i>
                                    </div>
                                </div>
                                <small class="text-muted">Unggah beberapa file (jpg, png, pdf).</small>
                            </div>
                            <div class="col-12">
                                <label class="form-label">Catatan <span class="text-danger">*</span></label>
                                <textarea name="return_dispatch_note" class="form-control" rows="3" required>{{ old('return_dispatch_note', $purchase_return->return_dispatch_note) }}</textarea>
                            </div>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Batal</button>
                        <button type="button" class="btn btn-primary" id="dispatch-request-confirm-trigger">Ajukan</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
@endcan

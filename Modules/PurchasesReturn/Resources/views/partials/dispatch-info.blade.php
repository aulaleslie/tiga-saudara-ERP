@php $dispatchAttachments = $purchase_return->getMedia('return_awb_attachments'); @endphp
@if($dispatchAttachments->isNotEmpty() || $purchase_return->return_dispatch_status || ($purchase_return->return_shipping_amount ?? 0) > 0 || $purchase_return->return_dispatch_note)
    <div class="row g-4 mb-4">
        <div class="col-lg-12">
            <div class="h-100 border rounded p-3">
                <h6 class="text-uppercase text-muted small mb-3">Informasi Dispatch</h6>
                <dl class="row mb-0 small">
                    <dt class="col-4 text-muted">Status</dt>
                    <dd class="col-8 fw-semibold">
                        {{ $purchase_return->return_dispatch_status ? ucwords(str_replace('_', ' ', $purchase_return->return_dispatch_status)) : '-' }}
                    </dd>
                    <dt class="col-4 text-muted">Ongkir</dt>
                    <dd class="col-8 fw-semibold">{{ format_currency($purchase_return->return_shipping_amount ?? 0) }}</dd>
                    @if($purchase_return->return_dispatch_note)
                        <dt class="col-4 text-muted">Catatan</dt>
                        <dd class="col-8 fw-semibold">{{ $purchase_return->return_dispatch_note }}</dd>
                    @endif
                    @if($purchase_return->dispatch_rejection_reason)
                        <dt class="col-4 text-muted">Alasan Tolak</dt>
                        <dd class="col-8 fw-semibold">{{ $purchase_return->dispatch_rejection_reason }}</dd>
                    @endif
                </dl>
                @if($dispatchAttachments->isNotEmpty())
                    <div class="mt-3">
                        <div class="fw-semibold mb-2">Lampiran</div>
                        <ul class="list-unstyled mb-0">
                            @foreach($dispatchAttachments as $media)
                                <li class="mb-1">
                                    <a href="{{ $media->getUrl() }}" target="_blank" rel="noopener">
                                        {{ $media->file_name }}
                                    </a>
                                    <small class="text-muted">({{ $media->humanReadableSize }})</small>
                                </li>
                            @endforeach
                        </ul>
                    </div>
                @endif
            </div>
        </div>
    </div>
@endif

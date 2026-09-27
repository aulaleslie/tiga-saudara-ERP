<div>
    @if($showMinimumModal)
        <div class="modal fade show d-block" tabindex="-1" style="background-color: rgba(0,0,0,0.5); z-index: 1050;">
            <div class="modal-dialog modal-dialog-centered">
                <div class="modal-content border-0 shadow">
                    <div class="modal-header bg-primary text-white">
                        <h5 class="modal-title fs-6 fw-bold">
                            <i class="bi bi-sliders me-1"></i> Atur Batas Minimum Stok
                        </h5>
                        <button type="button" class="btn-close btn-close-white" wire:click="closeMinimumModal" aria-label="Close"></button>
                    </div>
                    <div class="modal-body">
                        {{-- Product Identity Context --}}
                        <div class="mb-3 p-3 bg-light rounded border">
                            <div class="fw-bold text-dark fs-6">{{ $modalProductName }}</div>
                            <div class="small text-muted">
                                <span>Kode: <code>{{ $modalProductCode }}</code></span>
                                @if($modalBarcode)
                                    <span class="ms-2">| Barcode: <code>{{ $modalBarcode }}</code></span>
                                @endif
                            </div>
                        </div>

                        {{-- Stock & Scope Explanation --}}
                        <div class="alert alert-info py-2 small mb-3">
                            <i class="bi bi-info-circle me-1"></i>
                            Batas minimum ini berlaku untuk <strong>total Stok Bagus (Good)</strong> gabungan di seluruh bisnis dan lokasi aktif.
                        </div>

                        <div class="row g-2 mb-3">
                            <div class="col-6">
                                <div class="border rounded p-2 text-center bg-light">
                                    <div class="text-muted small">Stok Good Global</div>
                                    <div class="fw-bold fs-5 text-primary">{{ number_format($modalGlobalGoodStock, 2) }}</div>
                                    <div class="small text-muted" style="font-size: 0.75rem;">
                                        Pajak: {{ $modalTaxGood }} | Non-Pajak: {{ $modalNonTaxGood }}
                                    </div>
                                    @if(($modalTaxBroken + $modalNonTaxBroken) > 0)
                                        <div class="small text-danger mt-1" style="font-size: 0.75rem;">
                                            Rusak: {{ number_format($modalTaxBroken + $modalNonTaxBroken, 2) }} (P: {{ $modalTaxBroken }} | NP: {{ $modalNonTaxBroken }})
                                        </div>
                                    @endif
                                </div>
                            </div>
                            <div class="col-6">
                                <div class="border rounded p-2 text-center bg-light">
                                    <div class="text-muted small">Penjualan ({{ $periodLabel }})</div>
                                    <div class="fw-bold fs-5 text-dark">{{ number_format($modalSoldQuantity, 2) }}</div>
                                    <div class="small text-muted" style="font-size: 0.75rem;">
                                        Terakhir: {{ $modalLastSaleDate ? \Carbon\Carbon::parse($modalLastSaleDate)->format('d/m/Y') : '-' }}
                                    </div>
                                </div>
                            </div>
                        </div>

                        {{-- Input Field --}}
                        <div class="mb-3">
                            <label class="form-label fw-semibold">Batas Minimum Stok Global</label>
                            <input type="number"
                                   min="0"
                                   step="1"
                                   wire:model="modalMinimumInput"
                                   class="form-control @if($modalErrorMessage) is-invalid @endif"
                                   placeholder="Masukkan batas minimum (misal: 10)">
                            @if($modalErrorMessage)
                                <div class="invalid-feedback d-block">{{ $modalErrorMessage }}</div>
                            @endif
                            <div class="form-text small text-muted">
                                Masukkan 0 jika tidak ingin menetapkan batas minimum untuk produk ini.
                            </div>
                        </div>
                    </div>
                    <div class="modal-footer bg-light">
                        <button type="button" class="btn btn-secondary btn-sm" wire:click="closeMinimumModal">Batal</button>
                        <button type="button" class="btn btn-primary btn-sm" wire:click="saveMinimumStock">
                            <span wire:loading wire:target="saveMinimumStock" class="spinner-border spinner-border-sm me-1" role="status"></span>
                            Simpan Perubahan
                        </button>
                    </div>
                </div>
            </div>
        </div>
    @endif
</div>

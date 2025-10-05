<div class="container-fluid py-3">
    <div class="d-flex flex-wrap justify-content-between align-items-start mb-3 gap-2">
        <div>
            <h3 class="mb-1">{{ $this->formTitle }}</h3>
            <p class="text-muted mb-0">Kelola retur penjualan berdasarkan pengiriman yang telah dilakukan.</p>
        </div>

        @if ($this->saleReference)
            <div class="text-end">
                <span class="badge bg-light text-dark border">Ref: {{ $this->saleReference }}</span>
                @if (property_exists($this, 'saleReturn'))
                    <div class="small text-muted mt-1 text-uppercase">Status Persetujuan: {{ ucfirst($this->saleReturn->approval_status ?? 'pending') }}</div>
                @endif
            </div>
        @endif
    </div>

    @if ($this->approvalLocked)
        <div class="alert alert-warning d-flex align-items-center gap-2" role="alert">
            <i class="bi bi-lock-fill"></i>
            <span>Retur ini telah disetujui. Data tidak dapat diubah.</span>
        </div>
    @endif

    <form wire:submit.prevent="submit" class="needs-validation" novalidate>
        <div class="card shadow-sm mb-4">
            <div class="card-header bg-white border-0 pb-0">
                <h5 class="mb-1">Informasi Penjualan</h5>
                <p class="text-muted small mb-0">Cari referensi penjualan untuk memuat detail pengiriman.</p>
            </div>
            <div class="card-body">
                <div class="row g-3">
                    <div class="col-lg-5">
                        <label class="form-label fw-semibold">Referensi Penjualan</label>
                        @if ($this->approvalLocked)
                            <input type="text" class="form-control" value="{{ $this->saleReference ?? '-' }}" disabled>
                        @else
                            <livewire:sales-return.sale-reference-search :key="'sale-reference-' . ($this->sale_id ?? 'new')" />
                        @endif
                        @error('sale_id')
                            <div class="invalid-feedback d-block">{{ $message }}</div>
                        @enderror
                    </div>

                    <div class="col-lg-4">
                        <label class="form-label fw-semibold">Pelanggan</label>
                        <input type="text" class="form-control" value="{{ $this->customerName ?? '-' }}" disabled>
                    </div>

                    <div class="col-lg-3">
                        <label class="form-label fw-semibold" for="date">Tanggal Retur</label>
                        <input type="date" id="date" class="form-control" wire:model.defer="date" @disabled($this->approvalLocked) required>
                        @error('date')
                            <div class="invalid-feedback d-block">{{ $message }}</div>
                        @enderror
                    </div>
                </div>
            </div>
        </div>

        <div class="card shadow-sm mb-4" style="overflow: visible;">
            <div class="card-header bg-white border-0 d-flex align-items-center">
                <div>
                    <h5 class="mb-1">Detail Produk yang Dapat Diretur</h5>
                    <p class="text-muted small mb-0">Atur jumlah produk yang akan dikembalikan dan nomor serinya bila diperlukan.</p>
                </div>
                <div class="ms-auto small text-muted">
                    @error('rows')
                        <span class="text-danger">{{ $message }}</span>
                    @enderror
                </div>
            </div>
            <div class="card-body" style="overflow: visible;">
                @if ($this->sale_id)
                    <livewire:sales-return.sale-return-table :rows="$rows" :sale-id="$sale_id" :sale-return-id="property_exists($this, 'saleReturn') ? $this->saleReturn->id : null" />
                @else
                    <div class="alert alert-light border mb-0" role="alert">
                        Pilih referensi penjualan terlebih dahulu untuk melihat daftar produk yang dapat diretur.
                    </div>
                @endif
            </div>

            @if ($grand_total > 0)
                <div class="card-footer bg-light d-flex justify-content-between align-items-center">
                    <span class="fw-semibold">Total Retur</span>
                    <span class="h5 mb-0 text-primary">{{ format_currency($grand_total) }}</span>
                </div>
            @endif
        </div>

        <div class="card shadow-sm mb-4">
            <div class="card-header bg-white border-0">
                <h5 class="mb-1">Catatan Tambahan</h5>
                <p class="text-muted small mb-0">Berikan informasi tambahan untuk tim terkait penanganan retur.</p>
            </div>
            <div class="card-body">
                <textarea id="note" class="form-control" wire:model.defer="note" rows="3" placeholder="Opsional" @disabled($this->approvalLocked)></textarea>
                @error('note')
                    <div class="invalid-feedback d-block">{{ $message }}</div>
                @enderror
            </div>
        </div>

        <div class="d-flex justify-content-end">
            <a href="{{ route('sale-returns.index') }}" class="btn btn-light border">Batal</a>
            @if (! $this->approvalLocked)
                <button type="submit" class="btn btn-primary ms-2" wire:loading.attr="disabled">
                    <span wire:loading.remove>{{ $this->submitLabel }}</span>
                    <span wire:loading class="spinner-border spinner-border-sm" role="status" aria-hidden="true"></span>
                </button>
            @endif
        </div>
    </form>
</div>

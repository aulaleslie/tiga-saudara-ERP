<div class="container-fluid py-3">
    <form id="purchase-return-form" wire:submit.prevent="submit" class="needs-validation" novalidate>
        @csrf

        <div class="card shadow-sm mb-4">
            <div class="card-header bg-white border-0 pb-0">
                <h5 class="mb-1">Informasi Retur</h5>
                <p class="text-muted small mb-0">Pilih pemasok, tanggal transaksi, dan lokasi penyerahan sebelum menambahkan produk.</p>
            </div>
            <div class="card-body">
                <div class="row">
                    <div class="col-lg-4 mb-3">
                        <label class="form-label fw-semibold" for="supplier">Pemasok</label>
                        <livewire:auto-complete.supplier-loader />
                        @error('supplier_id')
                            <div class="invalid-feedback d-block">{{ $message }}</div>
                        @enderror
                    </div>

                    <div class="col-lg-4 mb-3">
                        <label class="form-label fw-semibold" for="date">Tanggal Retur</label>
                        <input type="date" id="date" class="form-control" wire:model.defer="date" required>
                        @error('date')
                            <div class="invalid-feedback d-block">{{ $message }}</div>
                        @enderror
                    </div>

                    <div class="col-lg-4 mb-3">
                        <label class="form-label fw-semibold" for="location">Lokasi</label>
                        <livewire:auto-complete.location-business-loader
                            :settingId="session('setting_id')"
                            :locationId="$location_id"
                            label="Lokasi"
                            event-name="purchaseReturnLocationSelected"
                            name="location_id" />
                        @error('location_id')
                            <div class="invalid-feedback d-block">{{ $message }}</div>
                        @enderror
                    </div>
                </div>
            </div>
        </div>

        <div class="card shadow-sm mb-4">
            <div class="card-header bg-white border-0 d-flex align-items-center">
                <div>
                    <h5 class="mb-1">Detail Produk</h5>
                    <p class="text-muted small mb-0">Daftar produk yang akan diretur beserta informasi ketersediaan stok di lokasi terpilih.</p>
                </div>
                <div class="ms-auto small text-muted">
                    @error('rows')
                        <span class="text-danger">{{ $message }}</span>
                    @enderror
                </div>
            </div>
            <div class="card-body">
                <livewire:purchase-return.purchase-return-table :location-id="$location_id" />

                @if (!$supplier_id)
                    <div class="alert alert-light border mt-3 mb-0" role="alert">
                        Pilih pemasok terlebih dahulu untuk menampilkan daftar produk yang dapat diretur.
                    </div>
                @endif
            </div>

            @if ($grand_total > 0)
                <div class="card-footer bg-light d-flex justify-content-between align-items-center">
                    <span class="fw-semibold">Total Retur</span>
                    <span class="h5 mb-0 text-primary">Rp {{ number_format($grand_total, 2, ',', '.') }}</span>
                </div>
            @endif
        </div>

        <div class="alert alert-info d-flex align-items-center gap-2 mb-4" role="alert">
            <i class="bi bi-info-circle-fill"></i>
            <span>Metode penyelesaian retur akan ditentukan setelah dokumen disetujui oleh penanggung jawab.</span>
        </div>

        <div class="card shadow-sm mb-4">
            <div class="card-header bg-white border-0">
                <h5 class="mb-1">Catatan Tambahan</h5>
                <p class="text-muted small mb-0">Tambahkan informasi tambahan yang perlu diketahui oleh tim gudang atau akuntansi.</p>
            </div>
            <div class="card-body">
                <textarea id="note" class="form-control" wire:model.defer="note" rows="3" placeholder="Opsional"></textarea>
                @error('note')
                    <div class="invalid-feedback d-block">{{ $message }}</div>
                @enderror
            </div>
        </div>

        <div class="d-flex justify-content-end">
            <a href="{{ route('purchase-returns.index') }}" class="btn btn-light border">Batal</a>
            <button type="submit" class="btn btn-primary ms-2" wire:loading.attr="disabled">
                <span wire:loading.remove>Proses Retur</span>
                <span wire:loading class="spinner-border spinner-border-sm" role="status" aria-hidden="true"></span>
            </button>
        </div>
    </form>
</div>

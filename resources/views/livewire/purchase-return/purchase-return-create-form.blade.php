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

        <div class="card shadow-sm mb-4">
            <div class="card-header bg-white border-0">
                <h5 class="mb-1">Metode Penyelesaian</h5>
                <p class="text-muted small mb-0">Tentukan bagaimana retur akan diselesaikan dengan pemasok.</p>
            </div>
            <div class="card-body">
                <div class="row">
                    <div class="col-md-4 mb-3">
                        <div class="form-check form-check-inline w-100 p-3 border rounded @if($return_type === 'exchange') border-primary bg-light @endif">
                            <input class="form-check-input" type="radio" id="return_type_exchange" value="exchange" wire:model="return_type">
                            <label class="form-check-label ms-2" for="return_type_exchange">
                                <span class="d-block fw-semibold">Penggantian Produk</span>
                                <small class="text-muted">Produk yang diretur akan digantikan dengan produk baru.</small>
                            </label>
                        </div>
                    </div>
                    <div class="col-md-4 mb-3">
                        <div class="form-check form-check-inline w-100 p-3 border rounded @if($return_type === 'deposit') border-primary bg-light @endif">
                            <input class="form-check-input" type="radio" id="return_type_deposit" value="deposit" wire:model="return_type">
                            <label class="form-check-label ms-2" for="return_type_deposit">
                                <span class="d-block fw-semibold">Simpan sebagai Uang Muka</span>
                                <small class="text-muted">Nilai retur akan disimpan sebagai kredit pemasok.</small>
                            </label>
                        </div>
                    </div>
                    <div class="col-md-4 mb-3">
                        <div class="form-check form-check-inline w-100 p-3 border rounded @if($return_type === 'cash') border-primary bg-light @endif">
                            <input class="form-check-input" type="radio" id="return_type_cash" value="cash" wire:model="return_type">
                            <label class="form-check-label ms-2" for="return_type_cash">
                                <span class="d-block fw-semibold">Pengembalian Tunai</span>
                                <small class="text-muted">Pemasok mengembalikan dana secara tunai atau transfer.</small>
                            </label>
                        </div>
                    </div>
                </div>
                @error('return_type')
                    <div class="invalid-feedback d-block">{{ $message }}</div>
                @enderror

                @if($return_type === 'exchange')
                    <div class="mt-4">
                        <div class="d-flex align-items-center mb-2">
                            <h6 class="mb-0">Produk Pengganti</h6>
                            <button type="button" class="btn btn-sm btn-outline-primary ms-auto" wire:click="addReplacementGood">
                                <i class="bi bi-plus-circle"></i> Tambah Produk
                            </button>
                        </div>

                        <div class="table-responsive border rounded">
                            <table class="table table-sm table-hover mb-0">
                                <thead class="table-light">
                                    <tr class="text-center">
                                        <th style="width: 35%">Produk</th>
                                        <th style="width: 15%">Jumlah</th>
                                        <th style="width: 20%">Nilai Satuan</th>
                                        <th style="width: 20%">Subtotal</th>
                                        <th style="width: 10%"></th>
                                    </tr>
                                </thead>
                                <tbody>
                                    @forelse($replacement_goods as $index => $replacement)
                                        <tr>
                                            <td>
                                                <livewire:purchase-return.replacement-product-search
                                                    :index="$index"
                                                    wire:key="replacement-{{ $index }}" />
                                                @error("replacement_goods.$index.product_id")
                                                    <span class="invalid-feedback d-block">{{ $message }}</span>
                                                @enderror
                                            </td>
                                            <td class="text-center">
                                                <input type="number" min="0" class="form-control text-center"
                                                       wire:model.lazy="replacement_goods.{{ $index }}.quantity"
                                                       wire:change="recalculateReplacement({{ $index }})">
                                                @error("replacement_goods.$index.quantity")
                                                    <span class="invalid-feedback d-block">{{ $message }}</span>
                                                @enderror
                                            </td>
                                            <td>
                                                <input type="number" step="0.01" min="0" class="form-control text-end"
                                                       wire:model.lazy="replacement_goods.{{ $index }}.unit_value"
                                                       wire:change="recalculateReplacement({{ $index }})">
                                            </td>
                                            <td class="text-end align-middle">
                                                <span class="fw-semibold">Rp {{ number_format($replacement['sub_total'], 2, ',', '.') }}</span>
                                            </td>
                                            <td class="text-center align-middle">
                                                <button type="button" class="btn btn-outline-danger btn-sm"
                                                        wire:click="removeReplacementGood({{ $index }})">
                                                    <i class="bi bi-trash"></i>
                                                </button>
                                            </td>
                                        </tr>
                                    @empty
                                        <tr>
                                            <td colspan="5" class="text-center text-muted py-4">Belum ada produk pengganti.</td>
                                        </tr>
                                    @endforelse
                                </tbody>
                            </table>
                        </div>
                        @error('replacement_goods')
                            <div class="invalid-feedback d-block mt-2">{{ $message }}</div>
                        @enderror
                    </div>
                @elseif($return_type === 'deposit')
                    <div class="alert alert-info mt-4 mb-0" role="alert">
                        Nilai retur sebesar <strong>Rp {{ number_format($grand_total, 2, ',', '.') }}</strong> akan disimpan sebagai kredit pemasok.
                    </div>
                @elseif($return_type === 'cash')
                    <div class="mt-4">
                        <label class="form-label fw-semibold" for="cash_proof">Unggah Bukti Pengembalian Tunai</label>
                        <input type="file" id="cash_proof" class="form-control" wire:model="cash_proof" accept=".jpg,.jpeg,.png,.pdf">
                        <small class="text-muted">Terima file gambar atau PDF dengan ukuran maksimum yang diizinkan sistem.</small>
                        @error('cash_proof')
                            <div class="invalid-feedback d-block">{{ $message }}</div>
                        @enderror
                    </div>
                @endif
            </div>
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

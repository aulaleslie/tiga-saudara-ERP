<div class="container-fluid">
    {{-- Purchase Return Form --}}
    <form id="purchase-return-form" wire:submit.prevent="submit">
        @csrf

        {{-- Supplier & Date Inputs --}}
        <div class="form-row">
            <div class="col-lg-4">
                <div class="form-group">
                    <label for="supplier">Pemasok</label>
                    <livewire:auto-complete.supplier-loader/>
                    @error('supplier_id') <span class="text-danger">{{ $message }}</span> @enderror
                </div>
            </div>
            <div class="col-lg-4">
                <div class="form-group">
                    <label for="date">Tanggal Retur</label>
                    <input type="date" class="form-control" wire:model.defer="date" required>
                    @error('date') <span class="text-danger">{{ $message }}</span> @enderror
                </div>
            </div>
            <div class="col-lg-4">
                <div class="form-group">
                    <label for="location">Lokasi</label>
                    <livewire:auto-complete.location-business-loader
                        :settingId="session('setting_id')"
                        :locationId="$location_id"
                        label="Lokasi"
                        event-name="purchaseReturnLocationSelected"
                        name="location_id" />
                    @error('location_id') <span class="text-danger">{{ $message }}</span> @enderror
                </div>
            </div>
        </div>

        {{-- Livewire Components for Product Table --}}
        @error('rows') <span class="text-danger">{{ $message }}</span> @enderror
        <livewire:purchase-return.purchase-return-table :location-id="$location_id" />

        @if ($grand_total > 0)
            <div class="mt-3 text-end">
                <h5 class="fw-bold">
                    Grand Total: <span class="text-success">Rp {{ number_format($grand_total, 2, ',', '.') }}</span>
                </h5>
            </div>
        @endif

        <div class="form-group mt-4">
            <label for="return_type" class="d-block">Metode Penyelesaian</label>
            <div class="d-flex flex-wrap gap-3">
                <div class="form-check me-4">
                    <input class="form-check-input" type="radio" id="return_type_exchange" value="exchange" wire:model="return_type">
                    <label class="form-check-label" for="return_type_exchange">Penggantian Produk</label>
                </div>
                <div class="form-check me-4">
                    <input class="form-check-input" type="radio" id="return_type_deposit" value="deposit" wire:model="return_type">
                    <label class="form-check-label" for="return_type_deposit">Simpan sebagai Uang Muka</label>
                </div>
                <div class="form-check">
                    <input class="form-check-input" type="radio" id="return_type_cash" value="cash" wire:model="return_type">
                    <label class="form-check-label" for="return_type_cash">Pengembalian Tunai</label>
                </div>
            </div>
            @error('return_type') <span class="text-danger d-block">{{ $message }}</span> @enderror
        </div>

        @if($return_type === 'exchange')
            <div class="card border-primary mb-3">
                <div class="card-header d-flex align-items-center">
                    <span class="fw-semibold">Produk Pengganti</span>
                    <button type="button" class="btn btn-sm btn-outline-primary ms-auto" wire:click="addReplacementGood">
                        <i class="bi bi-plus-circle"></i> Tambah Produk Pengganti
                    </button>
                </div>
                <div class="card-body p-0">
                    <div class="table-responsive">
                        <table class="table table-bordered mb-0">
                            <thead class="table-light">
                            <tr class="text-center">
                                <th style="width: 30%">Produk</th>
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
                                            <span class="text-danger">{{ $message }}</span>
                                        @enderror
                                    </td>
                                    <td class="text-center">
                                        <input type="number" min="0" class="form-control text-center"
                                               wire:model.lazy="replacement_goods.{{ $index }}.quantity"
                                               wire:change="recalculateReplacement({{ $index }})">
                                        @error("replacement_goods.$index.quantity")
                                            <span class="text-danger">{{ $message }}</span>
                                        @enderror
                                    </td>
                                    <td class="text-end">
                                        <input type="number" step="0.01" min="0" class="form-control text-end"
                                               wire:model.lazy="replacement_goods.{{ $index }}.unit_value"
                                               wire:change="recalculateReplacement({{ $index }})">
                                    </td>
                                    <td class="text-end align-middle">
                                        Rp {{ number_format($replacement['sub_total'], 2, ',', '.') }}
                                    </td>
                                    <td class="text-center align-middle">
                                        <button type="button" class="btn btn-sm btn-outline-danger"
                                                wire:click="removeReplacementGood({{ $index }})">
                                            <i class="bi bi-trash"></i>
                                        </button>
                                    </td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="5" class="text-center text-muted py-3">Belum ada produk pengganti.</td>
                                </tr>
                            @endforelse
                            </tbody>
                        </table>
                    </div>
                </div>
                @error('replacement_goods')
                    <div class="card-footer text-danger">{{ $message }}</div>
                @enderror
            </div>
        @elseif($return_type === 'deposit')
            <div class="alert alert-info">
                Nilai retur sebesar <strong>Rp {{ number_format($grand_total, 2, ',', '.') }}</strong> akan disimpan sebagai kredit pemasok.
            </div>
        @elseif($return_type === 'cash')
            <div class="form-group">
                <label for="cash_proof">Unggah Bukti Pengembalian Tunai</label>
                <input type="file" class="form-control" wire:model="cash_proof" accept=".jpg,.jpeg,.png,.pdf">
                @error('cash_proof') <span class="text-danger">{{ $message }}</span> @enderror
            </div>
        @endif

        {{-- Note Input --}}
        <div class="form-group mt-3">
            <label for="note">Catatan</label>
            <textarea id="note" class="form-control" wire:model.defer="note" rows="3" placeholder="Tambahkan catatan (Opsional)"></textarea>
            @error('note') <span class="text-danger">{{ $message }}</span> @enderror
        </div>

        {{-- Submit Button --}}
        <div class="mt-3">
            <button type="submit" class="btn btn-primary" wire:loading.attr="disabled">Proses Retur</button>
            <a href="{{ route('purchase-returns.index') }}" class="btn btn-secondary">Kembali</a>
        </div>
    </form>
</div>

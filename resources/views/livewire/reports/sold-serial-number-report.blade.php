<div>
    <div class="card border-0 shadow-sm mb-3">
        <div class="card-body">
            <div class="alert alert-info mb-3" role="alert">
                <strong>Informasi Laporan:</strong> Tanggal yang ditampilkan adalah tanggal penjualan asli.
                Retur fisik yang diterima dan penggantian yang disetujui di periode berikutnya akan memperbarui hasil periode sebelumnya secara otomatis.
            </div>

            <form wire:submit.prevent="generateReport" x-data="{ syncSelectors() { $dispatch('sync-business-selector'); } }" @submit="syncSelectors()">
                <div class="row align-items-end">
                    <div class="col-12 col-md-4 mb-3 mb-md-0">
                        @if(count($availableSettings) > 1)
                            @include('livewire.reports.business-checkbox-selector', [
                                'availableSettings' => $availableSettings,
                                'livewireProperty' => 'selectedSettingIds',
                                'label' => 'Bisnis',
                                'placeholder' => 'Pilih bisnis...',
                                'selectId' => 'soldSerialBusinessSelector',
                            ])
                        @elseif(count($availableSettings) === 1)
                            <label class="form-label small mb-1" for="soldSerialSingleBusiness">Bisnis</label>
                            <div id="soldSerialSingleBusiness" class="form-control d-flex align-items-center bg-light text-truncate" style="height: 38px;">
                                {{ $availableSettings[0]['company_name'] }}
                            </div>
                        @endif
                    </div>

                    <div class="col-12 col-md-4 mb-3 mb-md-0">
                        <label class="form-label small mb-1" for="soldSerialStartDate">Tanggal awal</label>
                        <input
                            id="soldSerialStartDate"
                            type="date"
                            class="form-control @error('startDate') is-invalid @enderror"
                            style="height: 38px;"
                            wire:model.live="startDate"
                        >
                        @error('startDate')
                            <div class="invalid-feedback">{{ $message }}</div>
                        @enderror
                    </div>

                    <div class="col-12 col-md-4">
                        <label class="form-label small mb-1" for="soldSerialEndDate">Tanggal akhir</label>
                        <input
                            id="soldSerialEndDate"
                            type="date"
                            class="form-control @error('endDate') is-invalid @enderror"
                            style="height: 38px;"
                            wire:model.live="endDate"
                        >
                        @error('endDate')
                            <div class="invalid-feedback">{{ $message }}</div>
                        @enderror
                    </div>
                </div>

                <div class="mt-3"
                    x-data="{
                        open: false,
                        showAllChips: false,
                        toggle() {
                            this.open = !this.open;
                            if (this.open) {
                                this.$nextTick(() => this.$refs.productSearchInput?.focus());
                            }
                        },
                        close(returnFocus = false) {
                            this.open = false;
                            if (returnFocus) {
                                this.$nextTick(() => this.$refs.productTrigger?.focus());
                            }
                        }
                    }"
                    @click.outside="close()"
                    @keydown.escape.prevent="close(true)">

                    <label class="form-label small mb-1" for="soldSerialProductTrigger">
                        Produk / Paket <span class="text-danger">*</span>
                    </label>

                    <div class="position-relative">
                        <button
                            type="button"
                            id="soldSerialProductTrigger"
                            x-ref="productTrigger"
                            class="btn form-control text-left d-flex justify-content-between align-items-center bg-white border @error('productIds') is-invalid @enderror"
                            style="height: 38px;"
                            aria-haspopup="listbox"
                            :aria-expanded="open"
                            @click="toggle()">
                            <span class="text-truncate flex-grow-1 mr-2 {{ count($productIds) === 0 ? 'text-muted' : '' }}">
                                @if(count($productIds) === 0)
                                    Pilih produk atau paket…
                                @else
                                    {{ count($productIds) }} produk dipilih
                                @endif
                            </span>
                            <i class="bi bi-chevron-down small text-muted flex-shrink-0"></i>
                        </button>

                        <div
                            x-show="open"
                            x-transition
                            class="position-absolute w-100 bg-white border rounded shadow-sm mt-1"
                            style="left: 0; z-index: 1050; max-width: 100%; display: none;"
                            role="listbox"
                            aria-multiselectable="true">

                            <div class="p-2 border-bottom bg-light">
                                <div class="position-relative">
                                    <input
                                        type="text"
                                        x-ref="productSearchInput"
                                        class="form-control form-control-sm"
                                        placeholder="Cari nama atau kode produk..."
                                        wire:model.live.debounce.300ms="productSearch"
                                        @keydown.escape.prevent="close(true)">
                                    <div class="position-absolute" style="right: 0.5rem; top: 50%; transform: translateY(-50%);" wire:loading wire:target="productSearch">
                                        <span class="spinner-border spinner-border-sm text-primary" role="status" aria-hidden="true"></span>
                                    </div>
                                </div>
                            </div>

                            <div style="max-height: 240px; overflow-y: auto;">
                                @forelse($searchResults as $product)
                                    <label
                                        wire:key="product-opt-{{ $product->id }}"
                                        class="dropdown-item d-flex align-items-start px-3 py-2 mb-0 user-select-none"
                                        style="cursor: pointer; white-space: normal;">
                                        <input
                                            type="checkbox"
                                            class="form-check-input position-static ml-0 mr-2 mt-1 flex-shrink-0"
                                            value="{{ $product->id }}"
                                            wire:model.live="productIds">
                                        <span class="d-flex flex-column text-break">
                                            <span class="font-weight-bold">{{ $product->product_name }}</span>
                                            <span class="text-muted small">{{ $product->product_code }}</span>
                                        </span>
                                    </label>
                                @empty
                                    <div class="px-3 py-3 text-muted small text-center">
                                        Tidak ada produk yang cocok.
                                    </div>
                                @endforelse
                            </div>

                            @if($hasMoreSearchResults)
                                <div class="px-3 py-2 border-top bg-light text-muted small">
                                    Menampilkan 25 hasil pertama. Persempit pencarian untuk hasil lebih spesifik.
                                </div>
                            @endif
                        </div>
                    </div>

                    @error('productIds')
                        <div class="text-danger small mt-1">{{ $message }}</div>
                    @enderror

                    @if($selectedProducts->isNotEmpty())
                        <div class="mt-2 d-flex flex-wrap align-items-center">
                            @foreach($selectedProducts as $index => $selected)
                                <span
                                    wire:key="product-chip-{{ $selected->id }}"
                                    class="badge badge-primary d-inline-flex align-items-center mr-1 mb-1 px-2 py-1 text-wrap text-left"
                                    @if($index >= 3) x-show.important="showAllChips" style="display: none;" @endif>
                                    <span>{{ $selected->product_name }}</span>
                                    <button
                                        type="button"
                                        class="close text-white ml-2"
                                        style="font-size: 0.9rem; line-height: 1; opacity: 0.9;"
                                        aria-label="Hapus {{ $selected->product_name }}"
                                        wire:click="removeProduct({{ $selected->id }})">
                                        <span aria-hidden="true">&times;</span>
                                    </button>
                                </span>
                            @endforeach

                            @if($selectedProducts->count() > 3)
                                <button
                                    type="button"
                                    class="btn btn-outline-secondary btn-sm py-0 px-2 mr-2 mb-1"
                                    @click="showAllChips = !showAllChips"
                                    x-text="showAllChips ? 'Sembunyikan' : '+{{ $selectedProducts->count() - 3 }} lainnya'">
                                </button>
                            @endif

                            <button
                                type="button"
                                class="btn btn-link btn-sm text-danger text-decoration-none p-0 mb-1"
                                wire:click="clearProducts">
                                Hapus semua
                            </button>
                        </div>
                    @endif
                </div>

                @if($hasPendingFilterChanges)
                    <div class="alert alert-warning mt-3 mb-0 py-2" role="status">
                        Filter berubah. Klik Tampilkan Laporan untuk memperbarui hasil.
                    </div>
                @endif

                @error('export')
                    <div class="alert alert-warning mt-3 mb-0 py-2">{{ $message }}</div>
                @enderror

                <div class="mt-3 d-flex flex-wrap">
                    <button
                        type="submit"
                        class="btn btn-primary mr-2 mb-1"
                        wire:loading.attr="disabled"
                        wire:target="generateReport,exportExcel"
                        @click="syncSelectors()">
                        <span wire:loading.remove wire:target="generateReport">Tampilkan Laporan</span>
                        <span wire:loading wire:target="generateReport">Memuat...</span>
                    </button>

                    <button
                        type="button"
                        class="btn btn-success mb-1"
                        wire:click="exportExcel"
                        wire:loading.attr="disabled"
                        wire:target="generateReport,exportExcel"
                        @disabled(! $hasAppliedFilters || $hasPendingFilterChanges)
                        @click="syncSelectors()">
                        <span wire:loading.remove wire:target="exportExcel">Export Excel</span>
                        <span wire:loading wire:target="exportExcel">Mengekspor...</span>
                    </button>
                </div>
            </form>
        </div>
    </div>

    @if($hasAppliedFilters)
        <div class="card border-0 shadow-sm">
            <div class="card-body">
                <div class="table-responsive">
                    <table class="table table-bordered table-striped mb-0">
                        <thead>
                            <tr>
                                <th>Product name</th>
                                <th>SN</th>
                                <th>Tgl</th>
                                <th>Bulan</th>
                                <th>Tahun</th>
                            </tr>
                        </thead>
                        <tbody>
                            @forelse($rows as $row)
                                <tr>
                                    <td>{{ $row['product_name'] }}</td>
                                    <td>{{ $row['sn'] }}</td>
                                    <td>{{ $row['day'] }}</td>
                                    <td>{{ $row['month'] }}</td>
                                    <td>{{ $row['year'] }}</td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="5" class="text-center text-muted py-4">
                                        Tidak ada serial number terjual yang cocok dengan filter.
                                    </td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>

                <div class="mt-3">
                    {{ $rows->links() }}
                </div>
            </div>
        </div>
    @endif
</div>

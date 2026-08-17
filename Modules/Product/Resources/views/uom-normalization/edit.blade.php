@extends('layouts.app')

@section('title', 'Normalisasi UOM Penerimaan')

@section('content')
<div class="container-fluid">
    <div class="row mb-4">
        <div class="col-12">
            <h1 class="h3">Normalisasi UOM Penerimaan</h1>
            <a href="{{ route('products.show', $product->id) }}" class="btn btn-outline-secondary btn-sm">
                <i class="cil-arrow-left"></i> Kembali ke Detail Produk
            </a>
        </div>
    </div>

    {{-- Product Identity Header --}}
    <div class="row mb-4">
        <div class="col-md-6">
            <div class="card">
                <div class="card-body">
                    <div class="row">
                        <div class="col-md-6">
                            <p class="text-muted small mb-1">Nama Produk</p>
                            <p class="font-weight-bold">{{ $product->product_name }}</p>
                        </div>
                        <div class="col-md-6">
                            <p class="text-muted small mb-1">Kode Produk</p>
                            <p class="font-weight-bold">{{ $product->product_code }}</p>
                        </div>
                    </div>
                    <div class="row mt-3">
                        <div class="col-md-6">
                            <p class="text-muted small mb-1">Unit Dasar Saat Ini</p>
                            <p class="font-weight-bold">{{ $product->baseUnit->name ?? 'N/A' }} ({{ $product->baseUnit->short_name ?? '—' }})</p>
                        </div>
                        <div class="col-md-6">
                            <p class="text-muted small mb-1">Total Stok Sistem</p>
                            <p class="font-weight-bold">{{ (float) $product->product_quantity }} {{ $product->baseUnit->short_name ?? '' }}</p>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    {{-- Normalization Form --}}
    <div id="uomNormalizationApp" x-data="uomNormalization({{ (int) $product->id }}, {{ (int) ($product->base_unit_id ?? 0) }}, '{{ addslashes($product->baseUnit->name ?? '') }}')" x-init="init()">

        {{-- Flash Error Message --}}
        <div class="alert alert-danger" x-show="errorMessage" x-cloak>
            <strong x-text="errorMessage"></strong>
            <button type="button" class="close" @click="errorMessage = null">
                <span>&times;</span>
            </button>
        </div>

        {{-- Step 1: Select Target UOM --}}
        <div class="card mb-4">
            <div class="card-header bg-light">
                <h6 class="mb-0">1. Pilih Unit Base Baru dan Faktor Konversi</h6>
            </div>
            <div class="card-body">
                <div class="alert alert-info mt-2">
                    <strong>Base Unit Saat Ini:</strong> <span x-text="baseUnitName"></span>
                </div>
                <div class="form-row">
                    <div class="form-group col-md-6">
                        <label for="uomTargetUnitSearchInput">Unit Base Baru</label>
                        <div class="position-relative" x-data="uomUnitSearch({{ (int) ($product->base_unit_id ?? 0) }})" x-init="init()">
                            <input
                                type="text"
                                id="uomTargetUnitSearchInput"
                                class="form-control"
                                x-model="query"
                                @input.debounce.500ms="search()"
                                @focus="open = true"
                                @blur="setTimeout(() => open = false, 150)"
                                placeholder="Ketik nama atau singkatan Unit..."
                                autocomplete="off"
                            >
                            <div class="dropdown-menu w-100 shadow show"
                                 x-show="open && results.length > 0"
                                 x-cloak
                                 style="position: absolute; z-index: 1050; max-height: 250px; overflow-y: auto; top: 100%; left: 0; right: 0;">
                                <template x-for="unit in results" :key="unit.id">
                                    <button type="button"
                                        @mousedown.prevent="selectUnit(unit)"
                                        class="dropdown-item"
                                        x-text="unit.display_name"></button>
                                </template>
                            </div>
                            <div class="dropdown-menu w-100 show"
                                 x-show="open && query.length >= 1 && results.length === 0 && !loading"
                                 x-cloak
                                 style="position: absolute; z-index: 1050; top: 100%; left: 0; right: 0;">
                                <div class="dropdown-item disabled">Unit tidak ditemukan...</div>
                            </div>
                        </div>
                        <div class="mt-2" x-show="targetUnitName">
                            <span class="badge badge-primary p-2" x-text="targetUnitName"></span>
                        </div>
                    </div>
                    <div class="form-group col-md-6">
                        <label for="factorInput">Faktor Konversi (Qty <span x-text="baseUnitName"></span> / Unit Base Baru)</label>
                        <input type="number" id="factorInput" class="form-control" x-model="factor" min="0.000001" step="any" @input="onFactorChanged()">
                    </div>
                </div>
            </div>
        </div>

        {{-- Step 2: Informational Purchase Lines --}}
        <div class="card mb-4" x-show="targetUnitId && factor > 0">
            <div class="card-header bg-light">
                <h6 class="mb-0">2. Baris Pembelian yang Dinormalisasi</h6>
            </div>
            <div class="card-body">
                <div x-show="loadingLines" class="text-muted small mb-2">Memuat baris pembelian...</div>
                
                <div x-show="!loadingLines && candidateLines.length === 0" class="alert alert-warning mb-0">
                    Tidak ada baris pembelian yang dapat dinormalisasi untuk produk ini di setting aktif.
                </div>

                <table class="table table-bordered table-sm" x-show="!loadingLines && candidateLines.length > 0">
                    <thead>
                        <tr>
                            <th>PO Ref</th>
                            <th>Produk</th>
                            <th>Qty Pesan</th>
                            <th>Qty Diterima</th>
                            <th>Status</th>
                            <th>Harga Satuan</th>
                            <th>Sub Total</th>
                        </tr>
                    </thead>
                    <tbody>
                        <template x-for="detail in candidateLines" :key="detail.id">
                            <tr>
                                <td x-text="detail.purchase_reference || '—'"></td>
                                <td x-text="detail.product_name + ' (' + detail.product_code + ')'"></td>
                                <td x-text="Number(detail.quantity).toFixed(3)"></td>
                                <td x-text="Number(detail.received_quantity).toFixed(3)"></td>
                                <td>
                                    <span x-show="detail.is_complete" class="badge badge-success">Lengkap</span>
                                    <span x-show="!detail.is_complete" class="badge badge-warning">Belum Lengkap</span>
                                </td>
                                <td x-text="formatCurrency(detail.unit_price)"></td>
                                <td x-text="formatCurrency(detail.sub_total)"></td>
                            </tr>
                        </template>
                    </tbody>
                </table>
            </div>
        </div>

        {{-- Step 3: Reason --}}
        <div class="card mb-4" x-show="candidateLines.length > 0 && targetUnitId && factor > 0">
            <div class="card-header bg-light">
                <h6 class="mb-0">3. Alasan Normalisasi</h6>
            </div>
            <div class="card-body">
                <div class="form-group">
                    <textarea class="form-control" x-model="reason" rows="3"
                        placeholder="Jelaskan alasan normalisasi UOM..."></textarea>
                </div>
            </div>
        </div>

        {{-- Preview Button --}}
        <div class="mb-4" x-show="candidateLines.length > 0 && targetUnitId && factor > 0">
            <button type="button" class="btn btn-info" @click="fetchPreview()" :disabled="loadingPreview">
                <span x-show="!loadingPreview"><i class="cil-search"></i> Pratinjau Normalisasi</span>
                <span x-show="loadingPreview"><i class="cil-reload cil-spin"></i> Memuat...</span>
            </button>
        </div>

        {{-- Preview Results --}}
        <div class="card mb-4" x-show="previewData" x-cloak>
            <div class="card-header" :class="previewData?.eligible ? 'bg-success text-white' : 'bg-warning'">
                <h6 class="mb-0">
                    <span x-show="previewData?.eligible">✓ Pratinjau Siap Dijalankan</span>
                    <span x-show="!previewData?.eligible">⚠ Pratinjau — Tidak Dapat Dijalankan</span>
                </h6>
            </div>
            <div class="card-body">
                {{-- Errors --}}
                <template x-if="previewData?.errors?.length > 0">
                    <div class="alert alert-danger">
                        <strong>Masalah:</strong>
                        <ul class="mb-0">
                            <template x-for="err in previewData.errors">
                                <li x-text="err"></li>
                            </template>
                        </ul>
                    </div>
                </template>

                {{-- Summary --}}
                <div class="row mb-3">
                    <div class="col-md-3">
                        <p class="text-muted small mb-1">Total Qty Asal</p>
                        <p class="font-weight-bold" x-text="previewData?.summary?.total_source_quantity"></p>
                    </div>
                    <div class="col-md-3">
                        <p class="text-muted small mb-1">Total Qty Normalisasi</p>
                        <p class="font-weight-bold" x-text="previewData?.summary?.total_normalized_quantity"></p>
                    </div>
                    <div class="col-md-3">
                        <p class="text-muted small mb-1">HPP Rata-rata Saat Ini</p>
                        <p class="font-weight-bold" x-text="formatCurrency(previewData?.summary?.current_average_hpp)"></p>
                    </div>
                    <div class="col-md-3">
                        <p class="text-muted small mb-1">HPP Proyeksi</p>
                        <p class="font-weight-bold" x-text="formatCurrency(previewData?.summary?.projected_hpp)"></p>
                    </div>
                </div>

                {{-- Purchase unit price rounding disclosure --}}
                <div class="alert alert-info small mb-3">
                    <i class="cil-info"></i>
                    Harga satuan pembelian dapat dibulatkan sesuai presisi mata uang. Nilai subtotal pemasok tetap menjadi nilai otoritatif dan tidak berubah.
                </div>

                {{-- Line Details --}}
                <table class="table table-bordered table-sm">
                    <thead>
                        <tr>
                            <th>PO</th>
                            <th>Lokasi</th>
                            <th>Qty Asal</th>
                            <th>→ Qty Normal</th>
                            <th>Harga Satuan Asal</th>
                            <th>Harga Satuan Normal</th>
                            <th>Sub Total Pemasok (Tetap)</th>
                            <th>Transaksi</th>
                        </tr>
                    </thead>
                    <tbody>
                        <template x-for="line in previewData?.lines" :key="line.received_note_detail_id">
                            <tr>
                                <td x-text="line.purchase_reference"></td>
                                <td x-text="line.location"></td>
                                <td x-text="line.source_quantity"></td>
                                <td x-text="line.normalized_quantity"></td>
                                <td x-text="formatCurrency(line.source_unit_price)"></td>
                                <td>
                                    <span x-text="formatCurrency(line.normalized_unit_price)"></span>
                                    <span x-show="line.has_unit_price_rounding_effect"
                                          class="badge badge-warning ml-1"
                                          title="Harga satuan dibulatkan">
                                        <i class="cil-warning"></i> Dibulatkan
                                    </span>
                                    <template x-if="line.has_unit_price_rounding_effect">
                                        <div class="text-muted small mt-1">
                                            <div>Harga satuan tepat: <span x-text="formatCurrencyPrecise(line.exact_normalized_unit_price)"></span></div>
                                            <div>Harga satuan tersimpan: <span x-text="formatCurrency(line.normalized_unit_price)"></span></div>
                                            <div>Selisih pembulatan tampilan: <span x-text="formatCurrencyPrecise(line.unit_price_rounding_effect)"></span> per unit</div>
                                        </div>
                                    </template>
                                </td>
                                <td x-text="formatCurrency(line.source_sub_total)"></td>
                                <td>
                                    <span x-show="line.transaction_match === 'matched'" class="badge badge-success">Cocok</span>
                                    <span x-show="line.transaction_match === 'missing'" class="badge badge-danger">Tidak Ditemukan</span>
                                    <span x-show="line.transaction_match === 'ambiguous'" class="badge badge-warning">Ambigu</span>
                                </td>
                            </tr>
                        </template>
                    </tbody>
                </table>

                {{-- Per-PurchaseDetail total --}}
                <template x-if="purchaseDetailTotals().length > 0">
                    <div class="mt-2">
                        <p class="small text-muted mb-1">Total per Detail Pembelian (bukan subtotal per baris penerimaan):</p>
                        <table class="table table-bordered table-sm">
                            <thead>
                                <tr>
                                    <th>Detail Pembelian</th>
                                    <th>Jumlah Baris Penerimaan</th>
                                    <th>Total Sub Total Pemasok</th>
                                </tr>
                            </thead>
                            <tbody>
                                <template x-for="pdTotal in purchaseDetailTotals()" :key="pdTotal.purchase_detail_id">
                                    <tr>
                                        <td x-text="'#' + pdTotal.purchase_detail_id"></td>
                                        <td x-text="pdTotal.line_count"></td>
                                        <td x-text="formatCurrency(pdTotal.purchase_detail_sub_total)"></td>
                                    </tr>
                                </template>
                            </tbody>
                        </table>
                    </div>
                </template>

                {{-- Execution Confirmations --}}
                <div class="mt-4" x-show="previewData?.eligible">
                    <div class="form-check mb-2">
                        <input class="form-check-input" type="checkbox" id="ack1" x-model="isAcknowledged">
                        <label class="form-check-label" for="ack1">
                            Saya mengerti bahwa tindakan ini tidak dapat dibatalkan, akan mengubah nilai HPP historis, dan berdampak pada stok.
                        </label>
                    </div>
                    <div class="form-check mb-3">
                        <input class="form-check-input" type="checkbox" id="ack2" x-model="isSalesPriceWarningAcknowledged">
                        <label class="form-check-label" for="ack2">
                            Saya bertanggung jawab penuh untuk meninjau kembali Harga Jual produk dan konversi yang mungkin terpengaruh.
                        </label>
                    </div>

                    <button type="button" class="btn btn-success btn-lg" @click="executeNormalization()" :disabled="executing || !isAcknowledged || !isSalesPriceWarningAcknowledged || reason.trim().length < 3">
                        <span x-show="!executing"><i class="cil-check-circle"></i> Jalankan Normalisasi</span>
                        <span x-show="executing"><i class="cil-reload cil-spin"></i> Memproses...</span>
                    </button>
                </div>
            </div>
        </div>

        {{-- Success Result --}}
        <div class="alert alert-success" x-show="executionResult" x-cloak>
            <strong>✓ Normalisasi berhasil!</strong>
            <p x-text="executionResult?.message"></p>
        </div>

        {{-- Confirmation Modal --}}
        <div class="modal fade" id="confirmationModal" tabindex="-1" role="dialog" aria-hidden="true" x-ref="confirmationModal">
            <div class="modal-dialog" role="document">
                <div class="modal-content">
                    <div class="modal-header">
                        <h5 class="modal-title">Konfirmasi Normalisasi</h5>
                        <button type="button" class="close" data-dismiss="modal" aria-label="Close">
                            <span aria-hidden="true">&times;</span>
                        </button>
                    </div>
                    <div class="modal-body">
                        Apakah Anda yakin ingin menjalankan normalisasi UOM? Tindakan ini tidak dapat dibatalkan.
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-dismiss="modal">Batal</button>
                        <button type="button" class="btn btn-danger" @click="proceedExecute()">Ya, Jalankan</button>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>
@endsection

@push('page_scripts')
<script>
function uomNormalization(productId, baseUnitId, baseUnitName) {
    return {
        productId: productId,
        baseUnitId: baseUnitId,
        baseUnitName: baseUnitName,
        targetUnitId: '',
        targetUnitName: '',
        factor: '',
        candidateLines: [],
        loadingLines: false,
        reason: '',
        isAcknowledged: false,
        isSalesPriceWarningAcknowledged: false,
        loadingPreview: false,
        executing: false,
        previewData: null,
        executionResult: null,
        errorMessage: null,

        init() {
            window.addEventListener('uomUnitSelected', (e) => this.onUnitSelected(e.detail));
            this.fetchCandidateLines();
        },

        onUnitSelected(unit) {
            this.targetUnitId = unit.id;
            this.targetUnitName = unit.display_name;
            this.previewData = null;
        },

        onFactorChanged() {
            this.previewData = null;
        },

        async fetchCandidateLines() {
            this.loadingLines = true;
            try {
                const response = await fetch("{{ route('products.uom-normalize.candidate-lines', $product->id) }}", {
                    headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                });
                this.candidateLines = await response.json();
            } catch (e) {
                this.errorMessage = 'Gagal memuat baris pembelian.';
                console.error(e);
            } finally {
                this.loadingLines = false;
            }
        },

        async fetchPreview() {
            this.loadingPreview = true;
            this.previewData = null;
            this.errorMessage = null;

            try {
                const response = await fetch("{{ route('products.uom-normalize.preview', $product->id) }}", {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-CSRF-TOKEN': '{{ csrf_token() }}',
                        'Accept': 'application/json',
                    },
                    body: JSON.stringify({
                        target_unit_id: parseInt(this.targetUnitId),
                        factor: parseFloat(this.factor),
                        purchase_detail_ids: this.candidateLines.map(l => l.id),
                    }),
                });

                const data = await response.json();
                if (data.success) {
                    this.previewData = data.preview;
                } else {
                    this.errorMessage = data.message || 'Gagal memuat pratinjau.';
                }
            } catch (e) {
                this.errorMessage = 'Terjadi kesalahan saat memuat pratinjau.';
                console.error(e);
            } finally {
                this.loadingPreview = false;
            }
        },

        executeNormalization() {
            $(this.$refs.confirmationModal).modal('show');
        },

        async proceedExecute() {
            $(this.$refs.confirmationModal).modal('hide');
            this.executing = true;
            this.executionResult = null;
            this.errorMessage = null;

            try {
                const response = await fetch("{{ route('products.uom-normalize.store', $product->id) }}", {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-CSRF-TOKEN': '{{ csrf_token() }}',
                        'Accept': 'application/json',
                    },
                    body: JSON.stringify({
                        target_unit_id: parseInt(this.targetUnitId),
                        factor: parseFloat(this.factor),
                        purchase_detail_ids: this.candidateLines.map(l => l.id),
                        reason: this.reason,
                        is_acknowledged: this.isAcknowledged,
                        is_sales_price_warning_acknowledged: this.isSalesPriceWarningAcknowledged,
                    }),
                });

                const data = await response.json();
                if (data.success) {
                    this.executionResult = data;
                    this.previewData = null;
                    // Redirect back to product show page
                    setTimeout(() => {
                        window.location.href = "{{ route('products.show', $product->id) }}";
                    }, 2000);
                } else {
                    this.errorMessage = data.message || 'Gagal menjalankan normalisasi.';
                }
            } catch (e) {
                this.errorMessage = 'Terjadi kesalahan saat menjalankan normalisasi.';
                console.error(e);
            } finally {
                this.executing = false;
            }
        },

        formatCurrency(value) {
            if (value === null || value === undefined) return '—';
            return new Intl.NumberFormat('id-ID', { style: 'currency', currency: 'IDR', minimumFractionDigits: 2 }).format(value);
        },

        formatCurrencyPrecise(value) {
            if (value === null || value === undefined) return '—';
            return new Intl.NumberFormat('id-ID', { style: 'currency', currency: 'IDR', minimumFractionDigits: 6, maximumFractionDigits: 6 }).format(value);
        },

        purchaseDetailTotals() {
            const lines = this.previewData?.lines || [];
            const grouped = {};
            for (const line of lines) {
                const id = line.purchase_detail_id;
                if (!grouped[id]) {
                    grouped[id] = {
                        purchase_detail_id: id,
                        line_count: 0,
                        purchase_detail_sub_total: line.purchase_detail_sub_total,
                    };
                }
                grouped[id].line_count += 1;
            }
            return Object.values(grouped).filter(g => g.line_count > 1);
        },
    };
}

function uomUnitSearch(excludeUnitId) {
    return {
        excludeUnitId: excludeUnitId,
        query: '',
        results: [],
        open: false,
        loading: false,
        abortController: null,

        init() {},

        async search() {
            if (this.query.length < 1) {
                this.results = [];
                this.open = false;
                return;
            }

            this.loading = true;
            this.open = true;

            if (this.abortController) {
                this.abortController.abort();
            }
            this.abortController = new AbortController();

            try {
                const params = new URLSearchParams({ query: this.query, limit: 20 });
                if (this.excludeUnitId) params.set('exclude_unit_id', this.excludeUnitId);

                const response = await fetch("{{ route('products.uom-normalize.units.search', $product->id) }}?" + params, {
                    signal: this.abortController.signal,
                    headers: { 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json' },
                });
                if (!response.ok) throw new Error('Network response was not ok');
                this.results = await response.json();
            } catch (error) {
                if (error.name !== 'AbortError') {
                    console.error('Unit search error:', error);
                    this.results = [];
                }
            } finally {
                this.loading = false;
            }
        },

        selectUnit(unit) {
            this.query = '';
            this.results = [];
            this.open = false;
            window.dispatchEvent(new CustomEvent('uomUnitSelected', { detail: unit }));
        },
    };
}
</script>
@endpush

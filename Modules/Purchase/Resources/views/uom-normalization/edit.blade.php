@extends('layouts.app')

@section('title', 'Normalisasi UOM Penerimaan')

@section('content')
<div class="container-fluid">
    <div class="row mb-4">
        <div class="col-12">
            <h1 class="h3">Normalisasi UOM Penerimaan</h1>
            <a href="{{ route('purchases.show', $purchase->id) }}" class="btn btn-outline-secondary btn-sm">
                <i class="cil-arrow-left"></i> Kembali ke Detail Pembelian
            </a>
        </div>
    </div>

    {{-- Purchase Summary --}}
    <div class="row mb-4">
        <div class="col-md-6">
            <div class="card">
                <div class="card-body">
                    <div class="row">
                        <div class="col-md-6">
                            <p class="text-muted small mb-1">Referensi</p>
                            <p class="font-weight-bold">{{ $purchase->reference }}</p>
                        </div>
                        <div class="col-md-6">
                            <p class="text-muted small mb-1">Pemasok</p>
                            <p class="font-weight-bold">{{ $purchase->supplier->supplier_name ?? '—' }}</p>
                        </div>
                    </div>
                    <div class="row mt-3">
                        <div class="col-md-6">
                            <p class="text-muted small mb-1">Status</p>
                            <p class="font-weight-bold">{{ $purchase->status }}</p>
                        </div>
                        <div class="col-md-6">
                            <p class="text-muted small mb-1">Total</p>
                            <p class="font-weight-bold">{{ format_currency($purchase->total_amount) }}</p>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    {{-- Normalization Form --}}
    <div id="uomNormalizationApp" x-data="uomNormalization()" x-init="init()">
        
        {{-- Flash Error Message --}}
        <div class="alert alert-danger" x-show="errorMessage" x-cloak>
            <strong x-text="errorMessage"></strong>
            <button type="button" class="close" @click="errorMessage = null">
                <span>&times;</span>
            </button>
        </div>

        {{-- Step 1: Select Product --}}
        <div class="card mb-4">
            <div class="card-header bg-light">
                <h6 class="mb-0">1. Pilih Produk</h6>
            </div>
            <div class="card-body">
                <div class="form-group">
                    <label for="productSelect">Produk</label>
                    <select id="productSelect" class="form-control" x-model="selectedProductId" @change="onProductChanged()">
                        <option value="">-- Pilih Produk --</option>
                        @foreach($products as $product)
                        <option value="{{ $product->id }}"
                            data-conversions="{{ $product->conversions->toJson() }}"
                            data-base-unit="{{ optional($product->baseUnit)->name }}">
                            {{ $product->product_name }} ({{ $product->product_code }})
                        </option>
                        @endforeach
                    </select>
                </div>
            </div>
        </div>

        {{-- Step 2: Select Conversion --}}
        <div class="card mb-4" x-show="selectedProductId">
            <div class="card-header bg-light">
                <h6 class="mb-0">2. Pilih Konversi UOM</h6>
            </div>
            <div class="card-body">
                <div class="form-group">
                    <label for="conversionSelect">Konversi</label>
                    <select id="conversionSelect" class="form-control" x-model="selectedConversionId" @change="onConversionChanged()">
                        <option value="">-- Pilih Konversi --</option>
                        <template x-for="conv in availableConversions" :key="conv.id">
                            <option :value="conv.id"
                                x-text="(conv.unit ? conv.unit.name : '?') + ' → ' + (conv.base_unit ? conv.base_unit.name : '?') + ' (×' + conv.conversion_factor + ')'">
                            </option>
                        </template>
                    </select>
                </div>
                <div class="alert alert-info mt-2" x-show="selectedConversionId">
                    <strong>Faktor Konversi:</strong>
                    <span x-text="selectedConversionFactor"></span>×
                    — setiap 1 <span x-text="sourceUnitName"></span> = <span x-text="selectedConversionFactor"></span> <span x-text="baseUnitName"></span>
                </div>
            </div>
        </div>

        {{-- Step 3: Select Purchase Lines --}}
        <div class="card mb-4" x-show="selectedConversionId">
            <div class="card-header bg-light">
                <h6 class="mb-0">3. Pilih Baris Pembelian</h6>
            </div>
            <div class="card-body">
                <table class="table table-bordered table-sm">
                    <thead>
                        <tr>
                            <th><input type="checkbox" @change="toggleAllLines($event)"></th>
                            <th>Produk</th>
                            <th>Qty Pesan</th>
                            <th>Qty Diterima</th>
                            <th>Status</th>
                            <th>Harga Satuan</th>
                            <th>Sub Total</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($purchase->purchaseDetails as $detail)
                        <tr>
                            <td>
                                <input type="checkbox" value="{{ $detail->id }}"
                                    data-product-id="{{ $detail->product_id }}"
                                    class="line-checkbox"
                                    @change="onLineToggled()">
                            </td>
                            <td>{{ $detail->product_name }} ({{ $detail->product_code }})</td>
                            <td>{{ number_format($detail->quantity, 3) }}</td>
                            <td>
                                @php
                                    $received = $detail->receivedNoteDetails
                                        ->filter(fn ($rnd) => $rnd->receivedNote && $rnd->receivedNote->status === 'APPROVED')
                                        ->sum('quantity_received');
                                @endphp
                                {{ number_format($received, 3) }}
                            </td>
                            <td>
                                @if($received >= $detail->quantity)
                                    <span class="badge badge-success">Lengkap</span>
                                @else
                                    <span class="badge badge-warning">Belum Lengkap</span>
                                @endif
                            </td>
                            <td>{{ format_currency($detail->unit_price) }}</td>
                            <td>{{ format_currency($detail->sub_total) }}</td>
                        </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </div>

        {{-- Step 4: Reason --}}
        <div class="card mb-4" x-show="selectedLines.length > 0">
            <div class="card-header bg-light">
                <h6 class="mb-0">4. Alasan Normalisasi</h6>
            </div>
            <div class="card-body">
                <div class="form-group">
                    <textarea class="form-control" x-model="reason" rows="3"
                        placeholder="Jelaskan alasan normalisasi UOM..."></textarea>
                </div>
            </div>
        </div>

        {{-- Preview Button --}}
        <div class="mb-4" x-show="selectedLines.length > 0 && selectedConversionId">
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

                {{-- Line Details --}}
                <table class="table table-bordered table-sm">
                    <thead>
                        <tr>
                            <th>PO</th>
                            <th>Lokasi</th>
                            <th>Qty Asal</th>
                            <th>→ Qty Normal</th>
                            <th>Sub Total</th>
                            <th>Biaya Satuan Normal</th>
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
                                <td x-text="formatCurrency(line.source_sub_total)"></td>
                                <td x-text="formatCurrency(line.normalized_unit_cost)"></td>
                                <td>
                                    <span x-show="line.transaction_match === 'matched'" class="badge badge-success">Cocok</span>
                                    <span x-show="line.transaction_match === 'missing'" class="badge badge-danger">Tidak Ditemukan</span>
                                    <span x-show="line.transaction_match === 'ambiguous'" class="badge badge-warning">Ambigu</span>
                                </td>
                            </tr>
                        </template>
                    </tbody>
                </table>

                {{-- Execute Button --}}
                <div class="mt-3" x-show="previewData?.eligible && reason.trim().length >= 3">
                    <button type="button" class="btn btn-success btn-lg" @click="executeNormalization()" :disabled="executing">
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
function uomNormalization() {
    return {
        selectedProductId: '',
        selectedConversionId: '',
        selectedConversionFactor: 0,
        sourceUnitName: '',
        baseUnitName: '',
        availableConversions: [],
        selectedLines: [],
        reason: '',
        loadingPreview: false,
        executing: false,
        previewData: null,
        executionResult: null,
        errorMessage: null,

        init() {},

        onProductChanged() {
            this.selectedConversionId = '';
            this.previewData = null;

            const select = document.getElementById('productSelect');
            const option = select.options[select.selectedIndex];
            if (!option || !option.dataset.conversions) {
                this.availableConversions = [];
                return;
            }

            try {
                this.availableConversions = JSON.parse(option.dataset.conversions);
                this.baseUnitName = option.dataset.baseUnit || '?';
            } catch (e) {
                this.availableConversions = [];
            }

            // Filter checkboxes to only show matching product lines
            this.updateLineVisibility();
        },

        onConversionChanged() {
            this.previewData = null;
            const conv = this.availableConversions.find(c => c.id == this.selectedConversionId);
            this.selectedConversionFactor = conv ? conv.conversion_factor : 0;
            this.sourceUnitName = conv && conv.unit ? conv.unit.name : '?';
            this.baseUnitName = conv && conv.base_unit ? conv.base_unit.name : '?';
        },

        updateLineVisibility() {
            const checkboxes = document.querySelectorAll('.line-checkbox');
            checkboxes.forEach(cb => {
                const row = cb.closest('tr');
                if (this.selectedProductId && cb.dataset.productId != this.selectedProductId) {
                    row.style.display = 'none';
                    cb.checked = false;
                } else {
                    row.style.display = '';
                }
            });
            this.onLineToggled();
        },

        toggleAllLines(event) {
            const checked = event.target.checked;
            const checkboxes = document.querySelectorAll('.line-checkbox');
            checkboxes.forEach(cb => {
                const row = cb.closest('tr');
                if (row.style.display !== 'none') {
                    cb.checked = checked;
                }
            });
            this.onLineToggled();
        },

        onLineToggled() {
            const checkboxes = document.querySelectorAll('.line-checkbox:checked');
            this.selectedLines = Array.from(checkboxes).map(cb => parseInt(cb.value));
            this.previewData = null;
        },

        async fetchPreview() {
            this.loadingPreview = true;
            this.previewData = null;
            this.errorMessage = null;

            try {
                const response = await fetch("{{ route('purchases.uom-normalize.preview', $purchase->id) }}", {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-CSRF-TOKEN': '{{ csrf_token() }}',
                        'Accept': 'application/json',
                    },
                    body: JSON.stringify({
                        product_id: parseInt(this.selectedProductId),
                        conversion_id: parseInt(this.selectedConversionId),
                        purchase_detail_ids: this.selectedLines,
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
                const response = await fetch("{{ route('purchases.uom-normalize.store', $purchase->id) }}", {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-CSRF-TOKEN': '{{ csrf_token() }}',
                        'Accept': 'application/json',
                    },
                    body: JSON.stringify({
                        product_id: parseInt(this.selectedProductId),
                        conversion_id: parseInt(this.selectedConversionId),
                        purchase_detail_ids: this.selectedLines,
                        reason: this.reason,
                    }),
                });

                const data = await response.json();
                if (data.success) {
                    this.executionResult = data;
                    this.previewData = null;
                    // Reload after short delay
                    setTimeout(() => {
                        window.location.href = "{{ route('purchases.show', $purchase->id) }}";
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
    };
}
</script>
@endpush

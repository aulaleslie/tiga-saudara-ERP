<div>
    {{-- Feedback / Alert Section --}}
    @if (session()->has('message'))
        <div class="alert alert-warning alert-dismissible fade show mb-3" role="alert">
            <div class="alert-body">
                <span>{{ session('message') }}</span>
                <button type="button" class="close" data-dismiss="alert" aria-label="Close">
                    <span aria-hidden="true">×</span>
                </button>
            </div>
        </div>
    @endif

    @if ($feedbackMessage)
        <div class="alert alert-{{ $feedbackType }} alert-dismissible fade show mb-3" role="alert">
            <div class="alert-body d-flex align-items-center justify-content-between">
                <span>
                    @if($feedbackType === 'success') <i class="bi bi-check-circle-fill mr-1"></i>
                    @elseif($feedbackType === 'danger') <i class="bi bi-x-circle-fill mr-1"></i>
                    @elseif($feedbackType === 'warning') <i class="bi bi-exclamation-triangle-fill mr-1"></i>
                    @else <i class="bi bi-info-circle-fill mr-1"></i>
                    @endif
                    {{ $feedbackMessage }}
                </span>
                <button type="button" class="close" wire:click="$set('feedbackMessage', null)" aria-label="Close">
                    <span aria-hidden="true">×</span>
                </button>
            </div>
        </div>
    @endif

    {{-- Scan Bar & Condition Toggle --}}
    <div class="card bg-light border mb-3 shadow-none">
        <div class="card-body py-3 px-3">
            <div class="row align-items-center">
                <div class="col-md-3 mb-2 mb-md-0">
                    <label class="font-weight-bold text-muted small text-uppercase mb-1 d-block">Kondisi Penghitungan</label>
                    <div class="btn-group btn-group-toggle w-100" data-toggle="buttons">
                        <button type="button"
                                id="opname-condition-good"
                                wire:click="setActiveCondition('good')"
                                class="btn {{ $activeCondition === 'good' ? 'btn-success active' : 'btn-outline-success' }} font-weight-bold">
                            <i class="bi bi-shield-check mr-1"></i> Barang Bagus
                        </button>
                        <button type="button"
                                id="opname-condition-bad"
                                wire:click="setActiveCondition('bad')"
                                class="btn {{ $activeCondition === 'bad' ? 'btn-danger active' : 'btn-outline-danger' }} font-weight-bold">
                            <i class="bi bi-shield-x mr-1"></i> Barang Rusak
                        </button>
                    </div>
                </div>
                <div class="col-md-7 mb-2 mb-md-0">
                    <label class="font-weight-bold text-muted small text-uppercase mb-1 d-block">
                        Pindai Barcode / Nomor Seri (Tekan Enter)
                    </label>
                    <div class="input-group">
                        <div class="input-group-prepend">
                            <span class="input-group-text bg-white border-right-0">
                                <i class="bi bi-upc-scan text-primary"></i>
                            </span>
                        </div>
                        <input type="text"
                               id="opname-scan-input"
                               class="form-control border-left-0"
                               placeholder="Pindai barcode produk, barcode konversi, atau nomor seri..."
                               wire:model="scanInput"
                               autofocus>
                        <div class="input-group-append">
                            <button type="button" class="btn btn-primary" id="opname-scan-button">
                                <i class="bi bi-arrow-return-left"></i> Pindai
                            </button>
                        </div>
                    </div>
                    <div id="opname-scan-error" class="alert alert-danger py-2 px-3 mt-2 mb-0 d-none"
                         role="alert" aria-live="assertive"></div>
                </div>
                <div class="col-md-2 text-md-right">
                    <label class="font-weight-bold text-muted small text-uppercase mb-1 d-block">&nbsp;</label>
                    <button type="button" class="btn btn-outline-secondary btn-block" wire:click="openSearchModal">
                        <i class="bi bi-search"></i> Cari Produk
                    </button>
                </div>
            </div>
        </div>
    </div>

    {{-- Hidden Form Fields for Form Persistence --}}
    <input type="hidden" name="count_draft" value="{{ $this->countDraftPayload }}">

    {{-- Product Opname Table --}}
    <div class="table-responsive border rounded bg-white">
        <div wire:loading.flex class="col-12 position-absolute justify-content-center align-items-center"
             style="top:0;right:0;left:0;bottom:0;background-color: rgba(255,255,255,0.7);z-index: 99;">
            <div class="spinner-border text-primary" role="status">
                <span class="sr-only">Memuat...</span>
            </div>
        </div>

        <table class="table table-hover table-striped mb-0">
            <thead class="thead-light">
            <tr class="align-middle text-center small text-uppercase">
                <th style="width: 4%;">No</th>
                <th class="text-left" style="width: {{ $canViewSystemStock ? '26%' : '36%' }};">Produk & Satuan Dasar</th>
                @can('adjustments.view-system-stock')
                    <th style="width: 14%;" class="bg-light">Stok Sistem (Bagus / Rusak)</th>
                @endcan
                <th style="width: {{ $canViewSystemStock ? '14%' : '20%' }};" class="bg-success text-white">Hasil Hitung Bagus</th>
                <th style="width: {{ $canViewSystemStock ? '14%' : '20%' }};" class="bg-danger text-white">Hasil Hitung Rusak</th>
                @can('adjustments.view-system-stock')
                    <th style="width: 16%;">Selisih (Bagus / Rusak)</th>
                @endcan
                <th style="width: {{ $canViewSystemStock ? '12%' : '20%' }};">Aksi</th>
            </tr>
            </thead>
            <tbody>
            @if(!empty($products))
                @foreach($products as $key => $product)
                    @php
                        $isSerialized = !empty($product['serial_number_required']);
                        $goodCount = (int) ($product['good_count'] ?? 0);
                        $badCount = (int) ($product['bad_count'] ?? 0);
                        $baselineGood = (int) ($product['baseline']['existing_good_total'] ?? 0);
                        $baselineBad = (int) ($product['baseline']['existing_bad_total'] ?? 0);
                        $diffGood = $goodCount - $baselineGood;
                        $diffBad = $badCount - $baselineBad;
                    @endphp
                    <tr class="align-middle" wire:key="opname-product-{{ $product['id'] }}">
                        <td class="align-middle text-center">{{ $key + 1 }}</td>
                        <td class="align-middle">
                            <div class="font-weight-bold">{{ $product['product_name'] }}</div>
                            <div class="text-muted small">
                                <code>{{ $product['product_code'] }}</code>
                                <span class="badge badge-secondary ml-1">{{ $product['unit'] }}</span>
                                @if($isSerialized)
                                    <span class="badge badge-info ml-1"><i class="bi bi-upc"></i> Nomor Seri</span>
                                @endif
                            </div>
                        </td>

                        {{-- Baseline System Stock (Protected) --}}
                        @can('adjustments.view-system-stock')
                            <td class="align-middle text-center bg-light">
                                <span class="badge badge-pill badge-light border text-dark">
                                    <i class="bi bi-shield-check text-success"></i> {{ $baselineGood }}
                                </span>
                                <span class="badge badge-pill badge-light border text-dark ml-1">
                                    <i class="bi bi-shield-x text-danger"></i> {{ $baselineBad }}
                                </span>
                            </td>
                        @endcan

                        {{-- Proposed Good Count --}}
                        <td class="align-middle text-center">
                            @if($isSerialized)
                                <div class="input-group input-group-sm">
                                    <input type="text" class="form-control text-center font-weight-bold text-success bg-light"
                                           value="{{ $goodCount }}" readonly>
                                    <div class="input-group-append">
                                        <button type="button" class="btn btn-outline-success"
                                                wire:click="openSerialModal({{ $key }})"
                                                title="Kelola Serial Number">
                                             <i class="bi bi-list-ol"></i>
                                        </button>
                                    </div>
                                </div>
                            @else
                                <input type="number"
                                       class="form-control form-control-sm text-center font-weight-bold text-success opname-good-count"
                                       data-index="{{ $key }}"
                                       wire:model.lazy="products.{{ $key }}.good_count"
                                       wire:keydown.enter.prevent
                                       value="{{ $goodCount }}"
                                       inputmode="numeric" min="0">
                            @endif
                        </td>

                        {{-- Proposed Bad Count --}}
                        <td class="align-middle text-center">
                            @if($isSerialized)
                                <div class="input-group input-group-sm">
                                    <input type="text" class="form-control text-center font-weight-bold text-danger bg-light"
                                           value="{{ $badCount }}" readonly>
                                    <div class="input-group-append">
                                        <button type="button" class="btn btn-outline-danger"
                                                wire:click="openSerialModal({{ $key }})"
                                                title="Kelola Nomor Seri">
                                            <i class="bi bi-list-ol"></i>
                                        </button>
                                    </div>
                                </div>
                            @else
                                <input type="number"
                                       class="form-control form-control-sm text-center font-weight-bold text-danger opname-bad-count"
                                       data-index="{{ $key }}"
                                       wire:model.lazy="products.{{ $key }}.bad_count"
                                       wire:keydown.enter.prevent
                                       value="{{ $badCount }}"
                                       inputmode="numeric" min="0">
                            @endif
                        </td>

                        {{-- Signed Differences (Protected) --}}
                        @can('adjustments.view-system-stock')
                            <td class="align-middle text-center">
                                <span class="font-weight-bold {{ $diffGood > 0 ? 'text-primary' : ($diffGood < 0 ? 'text-danger' : 'text-muted') }}">
                                    {{ $diffGood > 0 ? '+' . $diffGood : $diffGood }}
                                </span>
                                <span class="text-muted">/</span>
                                <span class="font-weight-bold {{ $diffBad > 0 ? 'text-warning' : ($diffBad < 0 ? 'text-success' : 'text-muted') }}">
                                    {{ $diffBad > 0 ? '+' . $diffBad : $diffBad }}
                                </span>
                            </td>
                        @endcan

                        {{-- Action Buttons --}}
                        <td class="align-middle text-center">
                            @if($isSerialized)
                                <button type="button" class="btn btn-sm btn-info mr-1"
                                        wire:click="openSerialModal({{ $key }})"
                                        title="Kelola Nomor Seri">
                                    <i class="bi bi-upc"></i> Nomor Seri ({{ count($product['serial_numbers'] ?? []) }})
                                </button>
                            @endif
                            <button type="button" class="btn btn-sm btn-outline-danger"
                                    wire:click="removeProduct({{ $key }})"
                                    title="Hapus Produk">
                                <i class="bi bi-trash"></i>
                            </button>
                        </td>
                    </tr>
                @endforeach
            @else
                <tr>
                    <td colspan="{{ $canViewSystemStock ? 7 : 5 }}" class="text-center py-5 text-muted">
                        <i class="bi bi-box-seam display-4 d-block mb-2 text-secondary"></i>
                        <span class="font-weight-bold">Belum ada produk yang dihitung.</span><br>
                        <small>Pindai barcode di atas atau klik "Cari Produk" untuk memulai penghitungan stok fisik.</small>
                    </td>
                </tr>
            @endif
            </tbody>
        </table>
    </div>

    {{-- Modal: Product Search Dialog --}}
    @if($showSearchModal)
        <div class="modal fade show d-block" tabindex="-1" style="background: rgba(0,0,0,0.5); z-index: 1060;">
            <div class="modal-dialog modal-lg">
                <div class="modal-content">
                    <div class="modal-header">
                        <h5 class="modal-title"><i class="bi bi-search mr-1"></i> Cari Produk (Stok Dikelola)</h5>
                        <button type="button" class="close" wire:click="closeSearchModal">
                            <span>&times;</span>
                        </button>
                    </div>
                    <div class="modal-body">
                        <div class="form-group">
                            <input type="text" class="form-control form-control-lg"
                                   placeholder="Ketik nama, kode, atau barcode produk..."
                                   wire:model.live.debounce.300ms="searchTerm"
                                   wire:keydown.enter.prevent="searchProducts"
                                   autofocus>
                        </div>

                        <div class="list-group list-group-flush border rounded" style="max-height: 350px; overflow-y: auto;">
                            @forelse($searchResults as $result)
                                <button type="button" class="list-group-item list-group-item-action d-flex justify-content-between align-items-center"
                                        wire:click="productSelected({{ json_encode($result) }})"
                                        wire:loading.attr="disabled"
                                        wire:target="productSelected">
                                    <div>
                                        <div class="font-weight-bold">{{ $result['product_name'] }}</div>
                                        <small class="text-muted">
                                            Kode: <code>{{ $result['product_code'] }}</code> | Barcode: <code>{{ $result['barcode'] ?? '-' }}</code> | Satuan: {{ $result['base_unit'] }}
                                        </small>
                                    </div>
                                    <div>
                                        @if($result['serial_number_required'])
                                            <span class="badge badge-info mr-2">Nomor Seri</span>
                                        @endif
                                        <span class="btn btn-sm btn-primary">Pilih</span>
                                    </div>
                                </button>
                            @empty
                                <div class="text-center py-4 text-muted">
                                    @if(trim($searchTerm) === '')
                                        <span>Ketik kata kunci untuk mencari produk.</span>
                                    @else
                                        <span>Produk tidak ditemukan.</span>
                                    @endif
                                </div>
                            @endforelse
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" wire:click="closeSearchModal">Tutup</button>
                    </div>
                </div>
            </div>
        </div>
    @endif

    {{-- Modal: Ambiguity Choice Dialog --}}
    @if($showAmbiguityModal)
        <div class="modal fade show d-block" tabindex="-1" style="background: rgba(0,0,0,0.5); z-index: 1060;">
            <div class="modal-dialog modal-lg">
                <div class="modal-content">
                    <div class="modal-header bg-warning text-dark">
                        <h5 class="modal-title font-weight-bold">
                            <i class="bi bi-question-circle-fill mr-1"></i> Barcode Terdeteksi Ganda (Ambigu)
                        </h5>
                        <button type="button" class="close" wire:click="closeAmbiguityModal">
                            <span>&times;</span>
                        </button>
                    </div>
                    <div class="modal-body">
                        <p class="text-muted">
                            Kode barcode yang dipindai cocok dengan beberapa interpretasi berikut. Silahkan pilih item yang Anda maksud:
                        </p>
                        <div class="list-group">
                            @foreach($ambiguousCandidates as $candIndex => $candidate)
                                <button type="button" class="list-group-item list-group-item-action d-flex justify-content-between align-items-center"
                                        wire:click="selectAmbiguousCandidate({{ $candIndex }})">
                                    <div>
                                        @php
                                            $typeBadge = match($candidate['type'] ?? '') {
                                                'product' => 'PRODUK',
                                                'conversion' => 'KONVERSI',
                                                'serial' => 'NOMOR SERI',
                                                default => strtoupper($candidate['type'] ?? ''),
                                            };
                                        @endphp
                                        <span class="badge badge-primary mr-2">{{ $typeBadge }}</span>
                                        <span class="font-weight-bold">{{ $candidate['description'] }}</span>
                                    </div>
                                    <span class="btn btn-sm btn-outline-primary">Pilih Item Ini</span>
                                </button>
                            @endforeach
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" wire:click="closeAmbiguityModal">Batal</button>
                    </div>
                </div>
            </div>
        </div>
    @endif

    {{-- Modal: Row Serial Dialog --}}
    @if($showSerialModal && $selectedRowIndexForSerials !== null && isset($products[$selectedRowIndexForSerials]))
        @php
            $modalProduct = $products[$selectedRowIndexForSerials];
            $rowSerials = $modalProduct['serial_numbers'] ?? [];
        @endphp
        <div class="modal fade show d-block" tabindex="-1" style="background: rgba(0,0,0,0.5); z-index: 1060;">
            <div class="modal-dialog modal-lg">
                <div class="modal-content">
                    <div class="modal-header">
                        <div>
                            <h5 class="modal-title font-weight-bold">
                                <i class="bi bi-upc-scan mr-1 text-primary"></i> Kelola Nomor Seri: {{ $modalProduct['product_name'] }}
                            </h5>
                            <small class="text-muted">Kode: {{ $modalProduct['product_code'] }} | Satuan: {{ $modalProduct['unit'] }}</small>
                        </div>
                        <button type="button" class="close" wire:click="closeSerialModal">
                            <span>&times;</span>
                        </button>
                    </div>
                    <div class="modal-body">
                        {{-- Row Serial Input --}}
                        <div class="card bg-light border p-3 mb-3">
                            <div class="row align-items-center">
                                <div class="col-md-4 mb-2 mb-md-0">
                                    <label class="small text-muted font-weight-bold mb-1">Kondisi Nomor Seri</label>
                                    <div class="btn-group btn-group-toggle w-100" data-toggle="buttons">
                                        <button type="button"
                                                wire:click="$set('rowSerialCondition', 'good')"
                                                class="btn btn-sm {{ $rowSerialCondition === 'good' ? 'btn-success active' : 'btn-outline-success' }}">
                                            Bagus
                                        </button>
                                        <button type="button"
                                                wire:click="$set('rowSerialCondition', 'bad')"
                                                class="btn btn-sm {{ $rowSerialCondition === 'bad' ? 'btn-danger active' : 'btn-outline-danger' }}">
                                            Rusak
                                        </button>
                                    </div>
                                </div>
                                <div class="col-md-8">
                                    <label class="small text-muted font-weight-bold mb-1">Input Nomor Seri (Tekan Enter)</label>
                                    <div class="input-group">
                                        <input type="text"
                                               class="form-control"
                                               placeholder="Ketik atau pindai nomor seri (bisa nomor seri baru/belum terdaftar)..."
                                               wire:model="rowSerialInput"
                                               wire:keydown.enter.prevent="addRowSerial"
                                               autofocus>
                                        <div class="input-group-append">
                                            <button type="button" class="btn btn-primary" wire:click="addRowSerial">
                                                <i class="bi bi-plus-circle"></i> Tambah
                                            </button>
                                        </div>
                                    </div>
                                    @if($rowSerialError)
                                        <span class="text-danger small font-weight-bold mt-1 d-block">{{ $rowSerialError }}</span>
                                    @endif
                                </div>
                            </div>
                        </div>

                        {{-- Serial Number List --}}
                        <div class="d-flex justify-content-between align-items-center mb-2">
                            <span class="font-weight-bold">
                                Daftar Nomor Seri Tercatat ({{ count($rowSerials) }})
                            </span>
                            <div>
                                <span class="badge badge-success">Bagus: {{ collect($rowSerials)->where('condition', 'good')->count() }}</span>
                                <span class="badge badge-danger ml-1">Rusak: {{ collect($rowSerials)->where('condition', 'bad')->count() }}</span>
                            </div>
                        </div>

                        <div class="table-responsive border rounded" style="max-height: 300px; overflow-y: auto;">
                            <table class="table table-sm table-hover mb-0">
                                <thead class="thead-light">
                                <tr class="text-center small">
                                    <th style="width: 5%;">No</th>
                                    <th class="text-left" style="width: 35%;">Nomor Seri</th>
                                    <th style="width: 25%;">Kondisi Usulan</th>
                                    <th class="text-left" style="width: 25%;">Sumber Sistem</th>
                                    <th style="width: 10%;">Aksi</th>
                                </tr>
                                </thead>
                                <tbody>
                                @forelse($rowSerials as $sIdx => $serial)
                                    <tr class="align-middle text-center">
                                        <td class="align-middle">{{ $sIdx + 1 }}</td>
                                        <td class="align-middle text-left font-weight-bold">
                                            <code>{{ $serial['serial_number'] }}</code>
                                        </td>
                                        <td class="align-middle">
                                            <div class="btn-group btn-group-sm">
                                                <button type="button"
                                                        class="btn btn-xs {{ $serial['condition'] === 'good' ? 'btn-success' : 'btn-outline-secondary' }}"
                                                        wire:click="reclassifySerial({{ $sIdx }}, 'good')">
                                                    Bagus
                                                </button>
                                                <button type="button"
                                                        class="btn btn-xs {{ $serial['condition'] === 'bad' ? 'btn-danger' : 'btn-outline-secondary' }}"
                                                        wire:click="reclassifySerial({{ $sIdx }}, 'bad')">
                                                    Rusak
                                                </button>
                                            </div>
                                        </td>
                                        <td class="align-middle text-left small">
                                            @if(!empty($serial['source_serial_id']))
                                                <span class="badge badge-info">{{ $serial['source_location_name'] ?? 'Sistem' }}</span>
                                                <span class="text-muted">{{ $serial['source_status'] ?? '' }}</span>
                                            @else
                                                <span class="badge badge-warning text-dark">Nomor Seri Baru (Draf)</span>
                                            @endif
                                        </td>
                                        <td class="align-middle">
                                            <button type="button" class="btn btn-sm btn-outline-danger"
                                                    wire:click="removeRowSerial({{ $sIdx }})">
                                                <i class="bi bi-trash"></i>
                                            </button>
                                        </td>
                                    </tr>
                                @empty
                                    <tr>
                                        <td colspan="5" class="text-center py-3 text-muted">
                                            Belum ada nomor seri yang dimasukkan untuk produk ini.
                                        </td>
                                    </tr>
                                @endforelse
                                </tbody>
                            </table>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-primary" wire:click="closeSerialModal">
                            Selesai & Tutup
                        </button>
                    </div>
                </div>
            </div>
        </div>
    @endif

    {{-- Modal: Confirm Location Change --}}
    @if($showLocationConfirmModal)
        <div class="modal fade show d-block" tabindex="-1" style="background: rgba(0,0,0,0.5); z-index: 1060;">
            <div class="modal-dialog">
                <div class="modal-content">
                    <div class="modal-header bg-danger text-white">
                        <h5 class="modal-title font-weight-bold">
                            <i class="bi bi-exclamation-triangle-fill mr-1"></i> Konfirmasi Perubahan Lokasi
                        </h5>
                    </div>
                    <div class="modal-body">
                        <p class="mb-0">
                            Mengubah lokasi akan <strong>menghapus seluruh produk dan hasil hitung</strong> yang telah Anda masukkan pada daftar saat ini. Apakah Anda yakin ingin mengganti lokasi?
                        </p>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" wire:click="cancelLocationChange">
                            Batal
                        </button>
                        <button type="button" class="btn btn-danger font-weight-bold" wire:click="confirmLocationChange">
                            Ya, Ganti Lokasi & Hapus Daftar
                        </button>
                    </div>
                </div>
            </div>
        </div>
    @endif

    <script>
        document.addEventListener('DOMContentLoaded', function () {
            function syncOpnameCounts(form) {
                if (!form) return;
                const draftInput = form.querySelector('input[name="count_draft"]');
                if (!draftInput || !draftInput.value) return;

                try {
                    let draft = JSON.parse(draftInput.value);
                    if (!draft || !Array.isArray(draft.rows)) return;

                    let modified = false;

                    function parseStrictNonNegativeInteger(valueStr) {
                        const trimmed = (valueStr || '').trim();
                        // Only accept strict non-negative integer string representations (e.g. "0", "12", reject "1e2", "1.5", "-3")
                        if (/^\d+$/.test(trimmed)) {
                            return parseInt(trimmed, 10);
                        }
                        // If empty or non-integer, retain the raw input as-is or null so backend validator can reject with clear Indonesian error
                        return trimmed === '' ? 0 : trimmed;
                    }

                    form.querySelectorAll('input.opname-good-count').forEach(function (input) {
                        const idx = parseInt(input.getAttribute('data-index'), 10);
                        if (!isNaN(idx) && draft.rows[idx] !== undefined) {
                            const val = parseStrictNonNegativeInteger(input.value);
                            draft.rows[idx].good_count = val;
                            modified = true;
                        }
                    });

                    form.querySelectorAll('input.opname-bad-count').forEach(function (input) {
                        const idx = parseInt(input.getAttribute('data-index'), 10);
                        if (!isNaN(idx) && draft.rows[idx] !== undefined) {
                            const val = parseStrictNonNegativeInteger(input.value);
                            draft.rows[idx].bad_count = val;
                            modified = true;
                        }
                    });

                    if (modified) {
                        draftInput.value = JSON.stringify(draft);
                    }
                } catch (e) {
                    console.error('Error synchronizing opname counts:', e);
                }
            }

            // Intercept submit event on forms containing this table before submission proceeds
            ['adjustment-create-form', 'adjustment-edit-form'].forEach(function (formId) {
                const form = document.getElementById(formId);
                if (form) {
                    // Prevent accidental Enter submission strictly on INPUT elements (e.g. text/number inputs),
                    // preserving normal Enter keyboard activation on buttons, dropdown options, and textarea.
                    form.addEventListener('keydown', function (e) {
                        if (e.key === 'Enter' && e.target && e.target.tagName === 'INPUT' && e.target.type !== 'submit') {
                            // If user is inside a text/number input, prevent browser from submitting the document form.
                            e.preventDefault();
                        }
                    });

                    form.addEventListener('submit', function () {
                        // Trigger blur on active element to ensure input value is committed
                        if (document.activeElement && typeof document.activeElement.blur === 'function') {
                            document.activeElement.blur();
                        }
                        syncOpnameCounts(form);
                    }, true); // Use capture phase so sync happens before other submit handlers or locks
                }
            });

            // Focus restoration listener for scanner input after closing modals or successful scans
            window.addEventListener('restore-scanner-focus', function () {
                setTimeout(function () {
                    const scanInput = document.getElementById('opname-scan-input');
                    if (scanInput) {
                        scanInput.focus();
                        if (typeof scanInput.select === 'function') {
                            scanInput.select();
                        }
                    }
                }, 50);
            });

            // Selection listener for unsuccessful scans: retain and select text for correction
            window.addEventListener('select-scan-input', function () {
                setTimeout(function () {
                    const scanInput = document.getElementById('opname-scan-input');
                    if (scanInput) {
                        scanInput.focus();
                        if (typeof scanInput.select === 'function') {
                            scanInput.select();
                        }
                    }
                }, 50);
            });
        });

        // =========================================================================
        // Duplicate-guarded, morph-safe FIFO scan controller for Stock Opname
        // =========================================================================
        if (!window.__opnameScanControllerInitialized) {
            window.__opnameScanControllerInitialized = true;

            (function () {
                const queue = [];
                let busy = false;
                let isAmbiguityModalOpen = false;

                const MAX_LOOKUP_ATTEMPTS = 20;

                function getElements() {
                    const scanInput = document.getElementById('opname-scan-input');
                    const scanButton = document.getElementById('opname-scan-button');
                    const scanError = document.getElementById('opname-scan-error');
                    const componentRoot = scanInput ? scanInput.closest('[wire\\:id]') : null;
                    const wireId = componentRoot ? componentRoot.getAttribute('wire:id') : null;
                    return { scanInput, scanButton, scanError, componentRoot, wireId };
                }

                function resolveComponent() {
                    const { wireId } = getElements();
                    if (!wireId || typeof Livewire === 'undefined' || typeof Livewire.find !== 'function') {
                        return null;
                    }
                    try {
                        return Livewire.find(wireId);
                    } catch (error) {
                        console.warn('Livewire component lookup failed.', error);
                        return null;
                    }
                }

                function showScanFeedback(message) {
                    const { scanInput, scanError } = getElements();
                    if (scanInput) {
                        scanInput.classList.add('is-invalid');
                        scanInput.title = message;
                    }
                    if (scanError) {
                        scanError.textContent = message;
                        scanError.classList.remove('d-none');
                    }
                }

                function clearScanFeedback() {
                    const { scanInput, scanError } = getElements();
                    if (scanInput) {
                        scanInput.classList.remove('is-invalid');
                        scanInput.removeAttribute('title');
                    }
                    if (scanError) {
                        scanError.textContent = '';
                        scanError.classList.add('d-none');
                    }
                }

                let pendingSyncRaf = null;
                let clientSelectedCondition = null;

                function cancelPendingSync() {
                    if (pendingSyncRaf !== null) {
                        cancelAnimationFrame(pendingSyncRaf);
                        pendingSyncRaf = null;
                    }
                }

                function getActiveCondition(component) {
                    if (clientSelectedCondition === 'good' || clientSelectedCondition === 'bad') {
                        return clientSelectedCondition;
                    }
                    const badBtn = document.getElementById('opname-condition-bad');
                    if (badBtn && (badBtn.classList.contains('active') || badBtn.classList.contains('btn-danger'))) {
                        return 'bad';
                    }
                    const goodBtn = document.getElementById('opname-condition-good');
                    if (goodBtn && (goodBtn.classList.contains('active') || goodBtn.classList.contains('btn-success'))) {
                        return 'good';
                    }
                    if (component) {
                        try {
                            const compCond = component.get('activeCondition');
                            if (compCond === 'good' || compCond === 'bad') {
                                return compCond;
                            }
                        } catch (e) {
                            // component lookup error fallback
                        }
                    }
                    return 'good';
                }

                function syncVisibleCounts(component) {
                    cancelPendingSync();
                    pendingSyncRaf = requestAnimationFrame(() => {
                        pendingSyncRaf = null;
                        const products = component.get('products');
                        if (!Array.isArray(products)) {
                            return;
                        }
                        const { componentRoot } = getElements();
                        const scope = componentRoot || document;

                        scope.querySelectorAll('input.opname-good-count').forEach((input) => {
                            if (document.activeElement === input) {
                                return;
                            }
                            const idx = parseInt(input.getAttribute('data-index'), 10);
                            if (!isNaN(idx) && products[idx] !== undefined) {
                                const goodVal = products[idx].good_count ?? 0;
                                if (typeof input._x_forceModelUpdate === 'function') {
                                    input._x_forceModelUpdate(goodVal);
                                } else {
                                    input.value = goodVal;
                                }
                            }
                        });

                        scope.querySelectorAll('input.opname-bad-count').forEach((input) => {
                            if (document.activeElement === input) {
                                return;
                            }
                            const idx = parseInt(input.getAttribute('data-index'), 10);
                            if (!isNaN(idx) && products[idx] !== undefined) {
                                const badVal = products[idx].bad_count ?? 0;
                                if (typeof input._x_forceModelUpdate === 'function') {
                                    input._x_forceModelUpdate(badVal);
                                } else {
                                    input.value = badVal;
                                }
                            }
                        });
                    });
                }

                async function drain() {
                    if (busy || isAmbiguityModalOpen) {
                        return;
                    }
                    busy = true;

                    try {
                        while (queue.length > 0) {
                            if (isAmbiguityModalOpen) {
                                break;
                            }

                            const next = queue[0];
                            const component = resolveComponent();
                            if (!component) {
                                next.attempts += 1;
                                if (next.attempts >= MAX_LOOKUP_ATTEMPTS) {
                                    queue.shift();
                                    console.error('Opname scanner: komponen tidak ditemukan. Scan discarded:', next.value);
                                    showScanFeedback('Gagal memproses pindaian "' + next.value + '": komponen tidak ditemukan. Muat ulang halaman.');
                                    continue;
                                }
                                await new Promise((resolve) => setTimeout(resolve, 100));
                                continue;
                            }

                            queue.shift();
                            try {
                                await component.call('processScan', next.value, next.condition);
                                syncVisibleCounts(component);
                            } catch (error) {
                                console.error('Opname scanner: processScan request failed; result unknown, not retried automatically.', error, next.value);
                                showScanFeedback('Status tidak diketahui untuk pindaian "' + next.value + '". Periksa hasil hitung yang tercatat, lalu pindai ulang bila belum bertambah.');
                            }

                            if (isAmbiguityModalOpen) {
                                break;
                            }
                        }
                    } finally {
                        busy = false;
                    }
                }

                function enqueue(rawValue) {
                    const value = (rawValue ?? '').trim();

                    const { scanInput } = getElements();
                    if (scanInput) {
                        scanInput.value = '';
                    }
                    const component = resolveComponent();
                    if (component) {
                        component.set('scanInput', '', false);
                    }

                    if (value === '') {
                        return;
                    }
                    clearScanFeedback();
                    const condition = getActiveCondition(component);
                    queue.push({ value: value, condition: condition, attempts: 0 });
                    drain();
                }

                // Delegated event listener for scan input keydown (guarded and morph-safe)
                document.addEventListener('keydown', function (event) {
                    if (event.target && event.target.id === 'opname-scan-input') {
                        if (event.key === 'Enter' || event.code === 'Enter') {
                            event.preventDefault();
                            enqueue(event.target.value);
                        }
                    }
                });

                // Delegated event listener for scan button click
                document.addEventListener('click', function (event) {
                    const btn = event.target ? event.target.closest('#opname-scan-button') : null;
                    if (btn) {
                        event.preventDefault();
                        const { scanInput } = getElements();
                        if (scanInput) {
                            enqueue(scanInput.value);
                        }
                        return;
                    }

                    const goodBtn = event.target ? event.target.closest('#opname-condition-good') : null;
                    if (goodBtn) {
                        clientSelectedCondition = 'good';
                        const badBtn = document.getElementById('opname-condition-bad');
                        goodBtn.classList.remove('btn-outline-success');
                        goodBtn.classList.add('btn-success', 'active');
                        if (badBtn) {
                            badBtn.classList.remove('btn-danger', 'active');
                            badBtn.classList.add('btn-outline-danger');
                        }
                        return;
                    }

                    const badBtn = event.target ? event.target.closest('#opname-condition-bad') : null;
                    if (badBtn) {
                        clientSelectedCondition = 'bad';
                        const goodBtn = document.getElementById('opname-condition-good');
                        badBtn.classList.remove('btn-outline-danger');
                        badBtn.classList.add('btn-danger', 'active');
                        if (goodBtn) {
                            goodBtn.classList.remove('btn-success', 'active');
                            goodBtn.classList.add('btn-outline-success');
                        }
                        return;
                    }
                });

                // Cancel pending background sync if user starts manually focusing/typing into count inputs
                document.addEventListener('focusin', function (event) {
                    if (event.target && (event.target.classList.contains('opname-good-count') || event.target.classList.contains('opname-bad-count'))) {
                        cancelPendingSync();
                    }
                });

                // Listen for ambiguity pause/resume events
                window.addEventListener('opname-ambiguity-opened', function () {
                    isAmbiguityModalOpen = true;
                });

                window.addEventListener('opname-ambiguity-closed', function () {
                    isAmbiguityModalOpen = false;
                    setTimeout(() => {
                        drain();
                    }, 50);
                });
            })();
        }
    </script>
</div>

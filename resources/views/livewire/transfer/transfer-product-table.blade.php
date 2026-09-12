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

    {{-- Scan Bar & Search Action --}}
    <div class="card bg-light border mb-3 shadow-none">
        <div class="card-body py-3 px-3">
            <div class="row align-items-center">
                <div class="col-md-9 mb-2 mb-md-0">
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
                               id="transfer-scan-input"
                               class="form-control border-left-0"
                               placeholder="{{ $originLocationId ? 'Pindai barcode produk, barcode konversi, atau nomor seri...' : 'Pilih Lokasi Asal terlebih dahulu...' }}"
                               wire:model="scanInput"
                               @if(!$originLocationId) disabled @endif
                               autofocus>
                        <div class="input-group-append">
                            <button type="button"
                                    class="btn btn-primary"
                                    id="transfer-scan-button"
                                    @if(!$originLocationId) disabled @endif>
                                <i class="bi bi-arrow-return-left"></i> Pindai
                            </button>
                        </div>
                    </div>
                    <div id="transfer-scan-error" class="alert alert-danger py-2 px-3 mt-2 mb-0 d-none"
                         role="alert" aria-live="assertive"></div>
                </div>
                <div class="col-md-3 text-md-right">
                    <label class="font-weight-bold text-muted small text-uppercase mb-1 d-block">&nbsp;</label>
                    <button type="button"
                            class="btn btn-outline-secondary btn-block"
                            wire:click="openSearchModal"
                            @if(!$originLocationId) disabled @endif>
                        <i class="bi bi-search"></i> Cari Produk
                    </button>
                </div>
            </div>
        </div>
    </div>

    {{-- Products Table --}}
    <div class="table-responsive border rounded bg-white position-relative">
        <div wire:loading.flex class="col-12 position-absolute justify-content-center align-items-center"
             style="top:0;right:0;left:0;bottom:0;background-color: rgba(255,255,255,0.7);z-index: 99;">
            <div class="spinner-border text-primary" role="status">
                <span class="sr-only">Memuat...</span>
            </div>
        </div>

        <table class="table table-hover table-bordered mb-0">
            <thead class="thead-light">
            <tr class="align-middle text-center small text-uppercase">
                <th style="width: 4%;">#</th>
                <th class="text-left" style="width: {{ $canViewSystemStock ? '30%' : '50%' }};">Nama Produk</th>
                @can('stockTransfers.view-system-stock')
                    <th style="width: 18%;">Stok Asal</th>
                @endcan
                <th style="width: {{ $canViewSystemStock ? '20%' : '36%' }};">Jumlah Transfer</th>
                @can('stockTransfers.view-system-stock')
                    <th style="width: 18%;">Alokasi Sistem</th>
                @endcan
                <th style="width: 10%;">Aksi</th>
            </tr>
            </thead>
            <tbody>
            @forelse ($products as $i => $p)
                @php
                    $serialRequired = !empty($p['serial_number_required']);
                    $serials = collect($p['serial_numbers'] ?? []);
                    $isBrokenMode = (bool) ($p['is_broken_mode'] ?? false);
                @endphp
                <tr class="align-middle" wire:key="transfer-product-{{ $p['id'] }}-{{ $i }}">
                    <td class="align-middle text-center">{{ $i + 1 }}</td>

                    <td class="align-middle">
                        <div class="font-weight-bold">{{ $p['product_name'] }}</div>
                        <div class="text-muted small">
                            <code>{{ $p['product_code'] }}</code>
                            @if($serialRequired)
                                <span class="badge badge-info ml-1"><i class="bi bi-upc"></i> Nomor Seri</span>
                            @endif
                        </div>

                        @if(!empty($tableValidationErrors["products.{$i}"]))
                            <span class="text-danger small font-weight-bold d-block mt-1">
                                {{ $tableValidationErrors["products.{$i}"] }}
                            </span>
                        @endif
                    </td>

                    {{-- Privileged Stock Breakdown --}}
                    @can('stockTransfers.view-system-stock')
                        <td class="align-middle text-center bg-light">
                            <div class="small">
                                @if($isBrokenMode)
                                    <div><strong>Rusak Non Pajak:</strong> {{ $p['stock']['broken_quantity_non_tax'] ?? 0 }}</div>
                                    <div><strong>Rusak Pajak:</strong> {{ $p['stock']['broken_quantity_tax'] ?? 0 }}</div>
                                    <div class="mt-1 font-weight-bold text-primary">
                                        Total: {{ ($p['stock']['broken_quantity_non_tax'] ?? 0) + ($p['stock']['broken_quantity_tax'] ?? 0) }}
                                    </div>
                                @else
                                    <div><strong>Non Pajak:</strong> {{ $p['stock']['quantity_non_tax'] ?? 0 }}</div>
                                    <div><strong>Pajak:</strong> {{ $p['stock']['quantity_tax'] ?? 0 }}</div>
                                    <div class="mt-1 font-weight-bold text-primary">
                                        Total: {{ ($p['stock']['quantity_non_tax'] ?? 0) + ($p['stock']['quantity_tax'] ?? 0) }}
                                    </div>
                                @endif
                            </div>
                        </td>
                    @endcan

                    {{-- Quantity Input or Serial Manager --}}
                    <td class="align-middle text-center">
                        @if($serialRequired)
                            <div class="d-flex flex-column align-items-center">
                                <div class="input-group input-group-sm mb-1" style="max-width: 160px;">
                                    <input type="text" class="form-control text-center font-weight-bold bg-light"
                                           value="{{ count($serials) }}" readonly>
                                    <div class="input-group-append">
                                        <button type="button" class="btn btn-outline-primary"
                                                wire:click="openSerialModal({{ $i }})"
                                                title="Kelola Nomor Seri">
                                             <i class="bi bi-list-ol"></i> Seri
                                        </button>
                                    </div>
                                </div>

                                @if($serials->isNotEmpty())
                                    <div class="d-flex flex-wrap justify-content-center mt-1">
                                        @foreach($serials as $serialIndex => $serial)
                                            <span class="badge badge-light border d-inline-flex align-items-center mb-1 mr-1">
                                                <span>{{ $serial['serial_number'] }}</span>
                                                <button type="button"
                                                    class="btn btn-link btn-sm text-danger p-0 ml-1"
                                                    wire:click="removeSerialNumber({{ $i }}, {{ $serialIndex }})"
                                                    title="Hapus nomor seri">
                                                    <i class="bi bi-x-circle"></i>
                                                </button>
                                            </span>
                                        @endforeach
                                    </div>
                                @endif

                                @if(!empty($serialNumberErrors[$i]))
                                    <span class="text-danger small font-weight-bold mt-1">
                                        {{ $serialNumberErrors[$i] }}
                                    </span>
                                @endif

                                @if(isset($tableValidationErrors["products.{$i}.serial_numbers"]))
                                    <span class="text-danger small font-weight-bold mt-1">
                                        {{ $tableValidationErrors["products.{$i}.serial_numbers"] }}
                                    </span>
                                @endif
                            </div>
                        @else
                            <input
                                type="number"
                                min="0"
                                class="form-control form-control-sm text-center font-weight-bold transfer-quantity-input"
                                data-quantity-input="transfer"
                                data-row-index="{{ $i }}"
                                wire:model.live="products.{{ $i }}.requested_quantity"
                                placeholder="Jumlah dasar"
                                title="Masukkan jumlah dalam satuan dasar."
                            >

                            @if(isset($tableValidationErrors["products.{$i}.requested_quantity"]))
                                <span class="text-danger small font-weight-bold mt-1 d-block">
                                    {{ $tableValidationErrors["products.{$i}.requested_quantity"] }}
                                </span>
                            @endif
                        @endif
                    </td>

                    {{-- Privileged Allocation Breakdown --}}
                    @can('stockTransfers.view-system-stock')
                        <td class="align-middle text-center">
                            <div class="small">
                                @if($serialRequired)
                                    @php
                                        $taxCount = $serials->filter(fn($s) => (bool)($s['taxable'] ?? false) && !(bool)($s['is_broken'] ?? false))->count();
                                        $nonTaxCount = $serials->filter(fn($s) => !(bool)($s['taxable'] ?? false) && !(bool)($s['is_broken'] ?? false))->count();
                                        $brokenTaxCount = $serials->filter(fn($s) => (bool)($s['taxable'] ?? false) && (bool)($s['is_broken'] ?? false))->count();
                                        $brokenNonTaxCount = $serials->filter(fn($s) => !(bool)($s['taxable'] ?? false) && (bool)($s['is_broken'] ?? false))->count();
                                    @endphp
                                    @if($isBrokenMode)
                                        @if($brokenNonTaxCount > 0)
                                            <div>Rusak Non Pajak: {{ $brokenNonTaxCount }}</div>
                                        @endif
                                        @if($brokenTaxCount > 0)
                                            <div class="text-warning">Rusak Pajak: {{ $brokenTaxCount }} <i class="bi bi-exclamation-circle"></i></div>
                                        @endif
                                    @else
                                        @if($nonTaxCount > 0)
                                            <div>Non Pajak: {{ $nonTaxCount }}</div>
                                        @endif
                                        @if($taxCount > 0)
                                            <div class="text-warning">Pajak: {{ $taxCount }} <i class="bi bi-exclamation-circle"></i></div>
                                        @endif
                                    @endif
                                    @if($serials->isEmpty())
                                        <span class="text-muted">-</span>
                                    @endif
                                @else
                                    @php
                                        if ($isBrokenMode) {
                                            $nonTaxAlloc = $p['broken_quantity_non_tax'] ?? 0;
                                            $taxAlloc = $p['broken_quantity_tax'] ?? 0;
                                        } else {
                                            $nonTaxAlloc = $p['quantity_non_tax'] ?? 0;
                                            $taxAlloc = $p['quantity_tax'] ?? 0;
                                        }
                                    @endphp
                                    @if($nonTaxAlloc > 0)
                                        <div>{{ $isBrokenMode ? 'R' : '' }}Non Pajak: {{ $nonTaxAlloc }}</div>
                                    @endif
                                    @if($taxAlloc > 0)
                                        <div class="text-warning">{{ $isBrokenMode ? 'R' : '' }}Pajak: {{ $taxAlloc }} <i class="bi bi-exclamation-circle" title="Stok pajak harus dikembalikan lintas lokasi"></i></div>
                                    @endif
                                    @if($nonTaxAlloc === 0 && $taxAlloc === 0)
                                        <span class="text-muted">-</span>
                                    @endif
                                @endif
                            </div>
                        </td>
                    @endcan

                    <td class="align-middle text-center">
                        <button
                            type="button"
                            class="btn btn-outline-danger btn-sm"
                            wire:click="removeProduct({{ $i }})"
                            title="Hapus baris produk"
                        >
                            <i class="bi bi-trash"></i>
                        </button>
                    </td>
                </tr>
            @empty
                <tr>
                    <td colspan="{{ $canViewSystemStock ? 6 : 4 }}" class="text-center py-5 text-muted">
                        <i class="bi bi-box-seam display-4 d-block mb-2 text-secondary"></i>
                        <span class="font-weight-bold">Belum ada produk yang dimasukkan.</span><br>
                        <small>Pindai barcode di atas atau klik "Cari Produk" untuk menambahkan produk ke daftar transfer.</small>
                    </td>
                </tr>
            @endforelse
            </tbody>
        </table>
    </div>

    {{-- Modal: Product Search Dialog --}}
    @if($showSearchModal)
        <div class="modal fade show d-block" tabindex="-1" role="dialog" aria-modal="true" aria-labelledby="productSearchModalTitle" style="background: rgba(0,0,0,0.5); z-index: 1060;">
            <div class="modal-dialog modal-lg" role="document">
                <div class="modal-content">
                    <div class="modal-header">
                        <h5 class="modal-title" id="productSearchModalTitle"><i class="bi bi-search mr-1"></i> Cari Produk (Stok Dikelola)</h5>
                        <button type="button" class="close" wire:click="closeSearchModal" aria-label="Tutup">
                            <span aria-hidden="true">&times;</span>
                        </button>
                    </div>
                    <div class="modal-body">
                        <div class="form-group">
                            <input type="text" class="form-control form-control-lg"
                                   placeholder="Ketik nama, kode, barcode, kategori, atau merek produk..."
                                   wire:model.live.debounce.300ms="searchTerm"
                                   wire:keydown.enter.prevent="searchProducts"
                                   autofocus>
                        </div>

                        <div class="list-group list-group-flush border rounded" style="max-height: 350px; overflow-y: auto;">
                            @forelse($searchResults as $result)
                                <button type="button" class="list-group-item list-group-item-action d-flex justify-content-between align-items-center"
                                        wire:click="selectSearchProduct({{ json_encode($result) }})"
                                        wire:loading.attr="disabled"
                                        wire:target="selectSearchProduct">
                                    <div>
                                        <div class="font-weight-bold">{{ $result['product_name'] }}</div>
                                        <small class="text-muted">
                                            Kode: <code>{{ $result['product_code'] }}</code> | Barcode: <code>{{ $result['barcode'] ?? '-' }}</code> | Satuan: {{ $result['base_unit'] }}
                                            @if(!empty($result['category_name'])) | Kategori: {{ $result['category_name'] }} @endif
                                            @if(!empty($result['brand_name'])) | Merek: {{ $result['brand_name'] }} @endif
                                        </small>
                                    </div>
                                    <div>
                                        @if($result['serial_number_required'])
                                            <span class="badge badge-info mr-2">Nomor Seri</span>
                                        @endif
                                        @can('stockTransfers.view-system-stock')
                                            @if(isset($result['stock_quantity']))
                                                <span class="badge badge-light border mr-2">Stok: {{ $result['stock_quantity'] }}</span>
                                            @endif
                                        @endcan
                                        <span class="btn btn-sm btn-primary">Pilih</span>
                                    </div>
                                </button>
                            @empty
                                <div class="text-center py-4 text-muted">
                                    @if(trim($searchTerm) === '')
                                        <span>Ketik kata kunci untuk mencari produk.</span>
                                    @else
                                        <span>Produk tidak ditemukan atau stok kosong di lokasi asal.</span>
                                    @endif
                                </div>
                            @endforelse
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" wire:click="closeSearchModal" aria-label="Tutup dialog pencarian produk">Tutup</button>
                    </div>
                </div>
            </div>
        </div>
    @endif

    {{-- Modal: Ambiguity Choice Dialog --}}
    @if($showAmbiguityModal)
        <div class="modal fade show d-block" tabindex="-1" role="dialog" aria-modal="true" aria-labelledby="ambiguityModalTitle" style="background: rgba(0,0,0,0.5); z-index: 1060;">
            <div class="modal-dialog modal-lg" role="document">
                <div class="modal-content">
                    <div class="modal-header bg-warning text-dark">
                        <h5 class="modal-title font-weight-bold" id="ambiguityModalTitle">
                            <i class="bi bi-question-circle-fill mr-1"></i> Barcode Terdeteksi Ganda (Ambigu)
                        </h5>
                        <button type="button" class="close" wire:click="closeAmbiguityModal" aria-label="Batal dan tutup pilihan ganda">
                            <span aria-hidden="true">&times;</span>
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
                        <button type="button" class="btn btn-secondary" wire:click="closeAmbiguityModal" aria-label="Batal">Batal</button>
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
        <div class="modal fade show d-block" tabindex="-1" role="dialog" aria-modal="true" aria-labelledby="serialModalTitle" style="background: rgba(0,0,0,0.5); z-index: 1060;">
            <div class="modal-dialog modal-lg" role="document">
                <div class="modal-content">
                    <div class="modal-header">
                        <div>
                            <h5 class="modal-title font-weight-bold" id="serialModalTitle">
                                <i class="bi bi-upc-scan mr-1 text-primary"></i> Kelola Nomor Seri: {{ $modalProduct['product_name'] }}
                            </h5>
                            <small class="text-muted">Kode: {{ $modalProduct['product_code'] }}</small>
                        </div>
                        <button type="button" class="close" wire:click="closeSerialModal" aria-label="Tutup dialog nomor seri">
                            <span aria-hidden="true">&times;</span>
                        </button>
                    </div>
                    <div class="modal-body">
                        {{-- Row Serial Input --}}
                        <div class="card bg-light border p-3 mb-3">
                            <label class="small text-muted font-weight-bold mb-1">Pindai / Ketik Nomor Seri Terdaftar (Tekan Enter)</label>
                            <div class="input-group">
                                <input type="text"
                                       class="form-control"
                                       placeholder="Ketik atau pindai nomor seri..."
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

                            {{-- Search eligible serials helper (only for users with view permission) --}}
                            @can('stockTransfers.view-system-stock')
                                <div class="mt-3">
                                    <label class="small text-muted font-weight-bold mb-1">Cari Nomor Seri Tersedia di Lokasi Asal</label>
                                    <input type="text"
                                           class="form-control form-control-sm mb-2"
                                           placeholder="Ketik sebagian nomor seri untuk mencari..."
                                           wire:model.live.debounce.300ms="serialSearchTerm">

                                    @if(!empty($serialSearchResults))
                                        <div class="list-group border rounded" style="max-height: 150px; overflow-y: auto;">
                                            @foreach($serialSearchResults as $eligible)
                                                <button type="button"
                                                        class="list-group-item list-group-item-action py-1 px-2 d-flex justify-content-between align-items-center small"
                                                        wire:click="selectEligibleSerial({{ $eligible['id'] }})">
                                                    <code>{{ $eligible['serial_number'] }}</code>
                                                    <span class="badge badge-primary">Pilih</span>
                                                </button>
                                            @endforeach
                                        </div>
                                    @endif
                                </div>
                            @endcan
                        </div>

                        {{-- Serial Number List --}}
                        <div class="d-flex justify-content-between align-items-center mb-2">
                            <span class="font-weight-bold">
                                Daftar Nomor Seri Dipilih ({{ count($rowSerials) }})
                            </span>
                        </div>

                        <div class="table-responsive border rounded" style="max-height: 250px; overflow-y: auto;">
                            <table class="table table-sm table-hover mb-0">
                                <thead class="thead-light">
                                <tr class="text-center small">
                                    <th style="width: 8%;">No</th>
                                    <th class="text-left" style="width: 76%;">Nomor Seri</th>
                                    <th style="width: 16%;">Aksi</th>
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
                                            <button type="button" class="btn btn-sm btn-outline-danger"
                                                    wire:click="removeRowSerial({{ $sIdx }})"
                                                    aria-label="Hapus nomor seri {{ $serial['serial_number'] }}">
                                                <i class="bi bi-trash"></i>
                                            </button>
                                        </td>
                                    </tr>
                                @empty
                                    <tr>
                                        <td colspan="3" class="text-center py-3 text-muted">
                                            Belum ada nomor seri yang dipilih untuk produk ini.
                                        </td>
                                    </tr>
                                @endforelse
                                </tbody>
                            </table>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-primary" wire:click="closeSerialModal" aria-label="Selesai dan tutup dialog nomor seri">
                            Selesai & Tutup
                        </button>
                    </div>
                </div>
            </div>
        </div>
    @endif

    <script>
        // =========================================================================
        // Duplicate-guarded focus listeners for Stock Transfer scanner
        // =========================================================================
        if (!window.__transferFocusListenersInitialized) {
            window.__transferFocusListenersInitialized = true;

            // Focus restoration listener for scanner input after closing modals or successful scans
            window.addEventListener('restore-scanner-focus', function () {
                setTimeout(function () {
                    const scanInput = document.getElementById('transfer-scan-input');
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
                    const scanInput = document.getElementById('transfer-scan-input');
                    if (scanInput) {
                        scanInput.focus();
                        if (typeof scanInput.select === 'function') {
                            scanInput.select();
                        }
                    }
                }, 50);
            });
        }

        // =========================================================================
        // Duplicate-guarded, morph-safe FIFO scan controller for Stock Transfer
        // =========================================================================
        if (!window.__transferScanControllerInitialized) {
            window.__transferScanControllerInitialized = true;

            (function () {
                const queue = [];
                let busy = false;
                let isAmbiguityModalOpen = false;
                let pendingSyncFrame = null;

                const MAX_LOOKUP_ATTEMPTS = 20;

                function getElements() {
                    const scanInput = document.getElementById('transfer-scan-input');
                    const scanButton = document.getElementById('transfer-scan-button');
                    const scanError = document.getElementById('transfer-scan-error');
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

                function syncVisibleQuantities(component) {
                    if (pendingSyncFrame !== null) {
                        cancelAnimationFrame(pendingSyncFrame);
                        pendingSyncFrame = null;
                    }

                    pendingSyncFrame = requestAnimationFrame(() => {
                        pendingSyncFrame = null;
                        if (!component || typeof component.get !== 'function') {
                            return;
                        }

                        const products = component.get('products') || [];
                        const { componentRoot } = getElements();
                        const scope = componentRoot || document;

                        scope.querySelectorAll('input[data-quantity-input="transfer"]').forEach((input) => {
                            // Do not overwrite an input while the operator is actively editing it
                            if (document.activeElement === input) {
                                return;
                            }

                            const rawIndex = input.getAttribute('data-row-index');
                            if (rawIndex === null || rawIndex === '') {
                                return;
                            }

                            const rowIndex = Number(rawIndex);
                            const rowProduct = products[rowIndex];
                            if (!rowProduct || rowProduct.serial_number_required) {
                                return;
                            }

                            const authoritativeQty = Number(rowProduct.requested_quantity ?? 0);

                            if (typeof input._x_forceModelUpdate === 'function') {
                                input._x_forceModelUpdate(authoritativeQty);
                            } else {
                                input.value = authoritativeQty;
                            }
                        });
                    });
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

                function generateOperationToken() {
                    return 'txscan-' + Date.now() + '-' + Math.random().toString(36).substring(2, 9);
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
                                    console.error('Transfer scanner: komponen tidak ditemukan. Scan discarded:', next.value);
                                    showScanFeedback('Gagal memproses pindaian "' + next.value + '": komponen tidak ditemukan. Muat ulang halaman.');
                                    continue;
                                }
                                await new Promise((resolve) => setTimeout(resolve, 100));
                                continue;
                            }

                            queue.shift();
                            try {
                                await component.call('processScan', next.value, next.token);
                                syncVisibleQuantities(component);
                            } catch (error) {
                                console.error('Transfer scanner: processScan request failed; result unknown, not retried automatically.', error, next.value);
                                showScanFeedback('Status tidak diketahui untuk pindaian "' + next.value + '". Periksa daftar produk, lalu pindai ulang bila belum bertambah.');
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
                    const token = generateOperationToken();
                    queue.push({ value: value, token: token, attempts: 0 });
                    drain();
                }

                // Delegated event listener for scan input keydown
                document.addEventListener('keydown', function (event) {
                    if (event.target && event.target.id === 'transfer-scan-input') {
                        if (event.key === 'Enter' || event.code === 'Enter') {
                            event.preventDefault();
                            enqueue(event.target.value);
                        }
                    }
                });

                // Delegated event listener for scan button click
                document.addEventListener('click', function (event) {
                    const btn = event.target ? event.target.closest('#transfer-scan-button') : null;
                    if (btn) {
                        event.preventDefault();
                        const { scanInput } = getElements();
                        if (scanInput) {
                            enqueue(scanInput.value);
                        }
                    }
                });

                // Listen for ambiguity pause/resume events
                window.addEventListener('transfer-ambiguity-opened', function () {
                    isAmbiguityModalOpen = true;
                });

                window.addEventListener('transfer-ambiguity-closed', function () {
                    isAmbiguityModalOpen = false;
                    const component = resolveComponent();
                    if (component) {
                        syncVisibleQuantities(component);
                    }
                    setTimeout(() => {
                        const comp = resolveComponent();
                        if (comp) {
                            syncVisibleQuantities(comp);
                        }
                        drain();
                    }, 50);
                });
            })();
        }
    </script>
</div>

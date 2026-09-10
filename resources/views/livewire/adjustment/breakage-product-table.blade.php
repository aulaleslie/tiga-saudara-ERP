<div>
    @if (session()->has('message'))
        <div class="alert alert-warning alert-dismissible fade show" role="alert">
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

    {{--
        Scan Bar. Queued client-side via a plain native `keydown` listener
        (see the <script> block below), not a plain
        wire:keydown.enter.prevent="processScan" binding: two rapid scans (a
        real handheld scanner firing back-to-back Enter events, or a very
        fast typist) can both start from the same Livewire snapshot before
        either round-trip completes, and both compute the same "+1" against
        that stale snapshot -- one increment is silently lost. Every queued
        value is guaranteed to run against the server state left behind by
        the PREVIOUS scan's completed response, so every completed scan
        contributes exactly once. Merely disabling the input while busy was
        rejected: it would drop scans typed/fired during that window rather
        than count them.

        This uses a native listener attached directly to the input (same
        approach as the POS scanner in sell.blade.php) rather than an
        Alpine x-data factory, because Alpine component registration
        embedded in Livewire-rendered markup is unreliable: Alpine may have
        already scanned/initialized the DOM before this element's factory
        function is defined, silently breaking `x-data`.
    --}}
    <div class="card bg-light border mb-3 shadow-none">
        <div class="card-body py-3 px-3">
            <div class="row align-items-center">
                <div class="col-md-10 mb-2 mb-md-0">
                    <label class="font-weight-bold text-muted small text-uppercase mb-1 d-block">
                        Pindai Barcode / Nomor Seri Barang Bagus (Tekan Enter)
                    </label>
                    <div class="input-group">
                        <div class="input-group-prepend">
                            <span class="input-group-text bg-white border-right-0">
                                <i class="bi bi-upc-scan text-primary"></i>
                            </span>
                        </div>
                        <input type="text"
                               id="breakage-scan-input"
                               class="form-control border-left-0"
                               placeholder="Pindai barcode produk, barcode konversi, atau nomor seri..."
                               wire:model="scanInput"
                               {{ !$locationId ? 'disabled' : '' }}
                               autofocus>
                        <div class="input-group-append">
                            <button type="button" class="btn btn-primary" id="breakage-scan-button"
                                    {{ !$locationId ? 'disabled' : '' }}>
                                <i class="bi bi-arrow-return-left"></i> Pindai
                            </button>
                        </div>
                    </div>
                    <div id="breakage-scan-error" class="alert alert-danger py-2 px-3 mt-2 mb-0 d-none"
                         role="alert" aria-live="assertive"></div>
                </div>
                <div class="col-md-2 text-md-right">
                    <label class="font-weight-bold text-muted small text-uppercase mb-1 d-block">&nbsp;</label>
                    <button type="button" class="btn btn-outline-secondary btn-block" wire:click="openSearchModal" {{ !$locationId ? 'disabled' : '' }}>
                        <i class="bi bi-search"></i> Cari Produk
                    </button>
                </div>
            </div>
        </div>
    </div>

    {{-- Hidden fields for form submission --}}
    <input type="hidden" name="location_id" value="{{ $locationId }}">
    @foreach($products as $key => $product)
        <input type="hidden" name="product_ids[]" value="{{ $product['id'] }}">
        <input type="hidden" name="quantities_tax[{{ $key }}]" value="{{ $this->wireQuantitiesTax[$key] ?? 0 }}">
        <input type="hidden" name="quantities_non_tax[{{ $key }}]" value="{{ $this->wireQuantitiesNonTax[$key] ?? 0 }}">
        @foreach($product['serial_numbers'] ?? [] as $serialNumber)
            <input type="hidden" name="serial_numbers[{{ $key }}][]" value="{{ $serialNumber['id'] }}">
        @endforeach
    @endforeach

    <div class="table-responsive border rounded bg-white">
        <div wire:loading.flex class="col-12 position-absolute justify-content-center align-items-center" style="top:0;right:0;left:0;bottom:0;background-color: rgba(255,255,255,0.5);z-index: 99;">
            <div class="spinner-border text-primary" role="status">
                <span class="sr-only">Memuat...</span>
            </div>
        </div>

        <table class="table table-hover table-striped mb-0">
            <thead class="thead-light">
            <tr class="align-middle text-center small text-uppercase">
                <th style="width: 4%;">No</th>
                <th class="text-left" style="width: {{ $canViewSystemStock ? '30%' : '46%' }};">Produk & Satuan Dasar</th>
                @if($canViewSystemStock)
                    <th style="width: 16%;">Stok Baik Tersedia</th>
                @endif
                <th style="width: 18%;">Kuantitas Rusak</th>
                @if($canViewSystemStock)
                    <th style="width: 16%;">Stok Rusak Saat Ini</th>
                @endif
                <th style="width: 16%;">Aksi</th>
            </tr>
            </thead>
            <tbody>
            @if(!empty($products))
                @foreach($products as $key => $product)
                    @php
                        $isSerialized = !empty($product['serial_number_required']);
                        $qty = (int) ($quantities[$key] ?? 0);
                        $stock = $stockByProductId[$product['id']] ?? null;
                        $available = $stock['available_good'] ?? null;
                        $currentBad = $stock ? ($stock['broken_quantity_tax'] + $stock['broken_quantity_non_tax']) : null;
                    @endphp
                    <tr class="align-middle" wire:key="breakage-product-{{ $product['id'] }}">
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
                        @if($canViewSystemStock)
                            <td class="align-middle text-center">
                                <span class="badge badge-pill badge-light border text-dark">
                                    <i class="bi bi-shield-check text-success"></i> {{ $available ?? 0 }} {{ $product['unit'] }}
                                </span>
                            </td>
                        @endif
                        <td class="align-middle text-center">
                            @if($isSerialized)
                                <div class="input-group input-group-sm justify-content-center">
                                    <input type="text" class="form-control text-center font-weight-bold text-danger bg-light" style="max-width: 90px;"
                                           value="{{ $qty }}" readonly>
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
                                       class="form-control form-control-sm text-center font-weight-bold text-danger"
                                       wire:model.lazy="quantities.{{ $key }}"
                                       wire:keydown.enter.prevent
                                       value="{{ $qty }}"
                                       inputmode="numeric" min="0"
                                       {{ $canViewSystemStock ? 'max='.$available : '' }}>
                            @endif
                            @error("quantities_tax.{$key}")
                                <div class="text-danger small mt-1">{{ $message }}</div>
                            @enderror
                            @if (!empty($serialNumberErrors[$key]))
                                <div class="text-danger small mt-1">{{ $serialNumberErrors[$key] }}</div>
                            @endif
                        </td>
                        @if($canViewSystemStock)
                            <td class="align-middle text-center">
                                <span class="badge badge-pill badge-light border text-dark">
                                    <i class="bi bi-shield-x text-danger"></i> {{ $currentBad ?? 0 }} {{ $product['unit'] }}
                                </span>
                            </td>
                        @endif
                        <td class="align-middle text-center">
                            @if($isSerialized)
                                <button type="button" class="btn btn-sm btn-info mr-1"
                                        wire:click="openSerialModal({{ $key }})"
                                        title="Kelola Nomor Seri">
                                    <i class="bi bi-upc"></i> Nomor Seri ({{ count($product['serial_numbers'] ?? []) }})
                                </button>
                            @endif
                            <button type="button" class="btn btn-sm btn-outline-danger" wire:click="removeProduct({{ $key }})">
                                <i class="bi bi-trash"></i>
                            </button>
                        </td>
                    </tr>
                @endforeach
            @else
                <tr>
                    <td colspan="{{ $canViewSystemStock ? 6 : 4 }}" class="text-center py-5 text-muted">
                        <i class="bi bi-box-seam display-4 d-block mb-2 text-secondary"></i>
                        <span class="font-weight-bold">Belum ada produk yang ditambahkan.</span><br>
                        <small>Pindai barcode di atas atau klik "Cari Produk" untuk memulai pencatatan barang rusak.</small>
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
                        <div class="card bg-light border p-3 mb-3">
                            <label class="small text-muted font-weight-bold mb-1">
                                Pindai / Ketik Nomor Seri Barang Bagus (Tekan Enter)
                            </label>
                            <div class="input-group">
                                <input type="text"
                                       class="form-control"
                                       placeholder="Nomor seri harus sudah terdaftar sebagai barang bagus di lokasi ini..."
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

                        <div class="d-flex justify-content-between align-items-center mb-2">
                            <span class="font-weight-bold">
                                Nomor Seri Rusak Tercatat ({{ count($rowSerials) }})
                            </span>
                        </div>

                        <div class="table-responsive border rounded" style="max-height: 300px; overflow-y: auto;">
                            <table class="table table-sm table-hover mb-0">
                                <thead class="thead-light">
                                <tr class="text-center small">
                                    <th style="width: 8%;">No</th>
                                    <th class="text-left" style="width: 72%;">Nomor Seri</th>
                                    <th style="width: 20%;">Aksi</th>
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
                                                    wire:click="removeRowSerial({{ $sIdx }})">
                                                <i class="bi bi-trash"></i>
                                            </button>
                                        </td>
                                    </tr>
                                @empty
                                    <tr>
                                        <td colspan="3" class="text-center py-3 text-muted">
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
                            Mengubah lokasi akan <strong>menghapus seluruh produk dan kuantitas</strong> yang telah Anda masukkan pada daftar saat ini. Apakah Anda yakin ingin mengganti lokasi?
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
        // Serializes barcode/serial scans so two rapid scans (a handheld
        // scanner firing back-to-back Enter keystrokes, or a very fast
        // typist) never both compute their "+1" against the same stale
        // server snapshot. Each queued value is only sent to
        // processScan(code) once the PREVIOUS queued call's round trip has
        // fully resolved, so every scan that actually gets processed
        // contributes exactly once -- nothing is silently dropped, just
        // deferred until its turn. Unlike the POS scanner's
        // scanResolveInFlight (which drops scans that arrive while busy),
        // this queues them, since the goal here is to count every scanned
        // unit.
        //
        // Uses a native `keydown` listener wired directly to the input,
        // mirroring the POS scanner pattern (sell.blade.php), rather than
        // an Alpine `x-data` factory: Alpine component registration
        // embedded in Livewire-rendered markup is unreliable here (Alpine
        // may already have scanned the DOM before the factory function is
        // defined), and the installed Livewire 3.0.5 has no directive to
        // guarantee ordering either. A plain listener has no such
        // ordering dependency.
        //
        // Initialization waits for `livewire:initialized` (fired once
        // Livewire.start() has run), not `DOMContentLoaded` -- this script
        // is rendered ahead of the layout's Livewire script, which itself
        // starts Livewire from a DOMContentLoaded listener, so a
        // DOMContentLoaded listener registered here would run BEFORE
        // Livewire.start(), leaving Livewire.find() unable to resolve the
        // component for any scan that happens early. This matches the
        // `livewire:initialized` pattern already used elsewhere in this
        // codebase (e.g. resources/views/livewire/business-selector.blade.php).
        document.addEventListener('livewire:initialized', function () {
            const scanInput = document.getElementById('breakage-scan-input');
            const scanButton = document.getElementById('breakage-scan-button');
            const scanError = document.getElementById('breakage-scan-error');
            if (!scanInput) {
                return;
            }

            const componentRoot = scanInput.closest('[wire\\:id]');
            const wireId = componentRoot ? componentRoot.getAttribute('wire:id') : null;

            const resolveComponent = () => {
                if (!wireId || typeof Livewire === 'undefined' || typeof Livewire.find !== 'function') {
                    return null;
                }
                try {
                    return Livewire.find(wireId);
                } catch (error) {
                    console.warn('Livewire component lookup failed.', error);
                    return null;
                }
            };

            const queue = [];
            let busy = false;

            const MAX_LOOKUP_ATTEMPTS = 20;

            // Livewire's DOM morph updates the input's `value` ATTRIBUTE
            // correctly, but the browser preserves the input element's
            // live DOM `value` PROPERTY as "dirty" once the user has
            // interacted with it (including via a prior scripted
            // assignment) -- so after a scan the rendered attribute and
            // Livewire/Alpine's model are both already correct, but the
            // number actually shown on screen can still lag behind. This
            // re-syncs every quantity input's visible value from
            // component.get('quantities') one frame after the morph has
            // applied, using `_x_forceModelUpdate` (Alpine's model setter)
            // when available so Alpine's own tracked model stays in sync
            // too, falling back to a plain `.value` assignment otherwise.
            // Inputs are re-queried each call (never cached) since the
            // morph can replace/reorder row elements.
            function syncVisibleQuantities(component) {
                requestAnimationFrame(() => {
                    const quantities = component.get('quantities');
                    const scope = componentRoot || document;

                    scope
                        .querySelectorAll('input[wire\\:model\\.lazy^="quantities."]')
                        .forEach((input) => {
                            const match = input
                                .getAttribute('wire:model.lazy')
                                ?.match(/^quantities\.(\d+)$/);

                            if (!match) {
                                return;
                            }

                            const value = quantities[Number(match[1])] ?? 0;

                            if (typeof input._x_forceModelUpdate === 'function') {
                                input._x_forceModelUpdate(value);
                            } else {
                                input.value = value;
                            }
                        });
                });
            }

            function showStuckFeedback(message) {
                scanInput.classList.add('is-invalid');
                scanInput.title = message;
                if (scanError) {
                    scanError.textContent = message;
                    scanError.classList.remove('d-none');
                }
            }

            function clearStuckFeedback() {
                scanInput.classList.remove('is-invalid');
                scanInput.removeAttribute('title');
                if (scanError) {
                    scanError.textContent = '';
                    scanError.classList.add('d-none');
                }
            }

            // Component-lookup failures are safe to retry: no request was
            // ever dispatched to the server, so nothing can have been
            // double-counted. Retained/retried up to MAX_LOOKUP_ATTEMPTS
            // with visible feedback if the component never becomes
            // resolvable.
            //
            // A component.call('processScan', ...) rejection is NOT safe
            // to retry: a rejection only means the response was lost (a
            // network error, dropped connection, etc.) -- it does not
            // prove the server never processed the mutation. The server
            // may already have incremented the quantity before the
            // response failed to arrive, so blindly resending the same
            // barcode risks incrementing it again. There is no
            // idempotency token on processScan() to de-duplicate a
            // resend, so instead of retrying, the entry is dropped from
            // the queue and an explicit "status unknown" error is shown,
            // asking the operator to verify the quantity and rescan if it
            // wasn't applied.
            async function drain() {
                if (busy) {
                    return;
                }
                busy = true;
                try {
                    while (queue.length > 0) {
                        const next = queue[0];
                        const component = resolveComponent();
                        if (!component) {
                            next.attempts += 1;
                            if (next.attempts >= MAX_LOOKUP_ATTEMPTS) {
                                queue.shift();
                                console.error('Breakage scanner: komponen tidak ditemukan. Scan discarded:', next.value);
                                showStuckFeedback('Gagal memproses pindaian "' + next.value + '": komponen tidak ditemukan. Muat ulang halaman.');
                                continue;
                            }
                            await new Promise((resolve) => setTimeout(resolve, 100));
                            continue;
                        }
                        queue.shift();
                        try {
                            await component.call('processScan', next.value);
                            syncVisibleQuantities(component);
                        } catch (error) {
                            console.error('Breakage scanner: processScan request failed; result unknown, not retried automatically.', error, next.value);
                            showStuckFeedback('Status tidak diketahui untuk pindaian "' + next.value + '". Periksa kuantitas rusak yang tercatat, lalu pindai ulang bila belum bertambah.');
                        }
                    }
                } finally {
                    busy = false;
                }
            }

            function enqueue(rawValue) {
                const value = (rawValue ?? '').trim();

                // Clear the visible input the instant a value is captured,
                // BEFORE the async call starts/continues -- not after the
                // server response comes back. Without this, a second scan
                // that arrives while the first request is still in flight
                // appends its keystrokes onto whatever the first barcode
                // left in the field (e.g.
                // "21504320479012150432047901"), which then fails to
                // resolve as any known barcode/serial. Clearing here is
                // safe precisely because processScan(code) is driven by
                // the captured argument, never by re-reading the input's
                // live value.
                scanInput.value = '';
                const component = resolveComponent();
                if (component) {
                    component.set('scanInput', '', false);
                }

                if (value === '') {
                    return;
                }
                clearStuckFeedback();
                queue.push({ value: value, attempts: 0 });
                drain();
            }

            scanInput.addEventListener('keydown', function (event) {
                if (event.key !== 'Enter' && event.code !== 'Enter') {
                    return;
                }
                event.preventDefault();
                enqueue(scanInput.value);
            });

            if (scanButton) {
                scanButton.addEventListener('click', function () {
                    enqueue(scanInput.value);
                });
            }

            window.addEventListener('restore-scanner-focus', function () {
                setTimeout(function () {
                    scanInput.focus();
                    if (typeof scanInput.select === 'function') {
                        scanInput.select();
                    }
                }, 50);
            });

            window.addEventListener('select-scan-input', function () {
                setTimeout(function () {
                    scanInput.focus();
                    if (typeof scanInput.select === 'function') {
                        scanInput.select();
                    }
                }, 50);
            });
        });
    </script>
</div>

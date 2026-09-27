@php use Modules\Adjustment\Entities\Transfer; @endphp
<div>
    @if($errorMessage)
        <div class="alert alert-danger" role="alert">{{ $errorMessage }}</div>
    @endif

    <div class="row">
        <div class="col-md-6">
            <label class="form-label d-block" id="v3-condition-label">Kondisi Barang</label>
            @if($transferId !== null)
                @if($stockCondition === Transfer::CONDITION_BREAKAGE)
                    <span class="badge badge-warning">Barang Rusak</span>
                @else
                    <span class="badge badge-success">Barang Baik</span>
                @endif
            @else
                <div class="btn-group" role="group" aria-labelledby="v3-condition-label">
                    <button type="button" wire:click="selectStockCondition('{{ Transfer::CONDITION_GOOD }}')"
                            class="btn {{ $stockCondition === Transfer::CONDITION_GOOD ? 'btn-success active' : 'btn-outline-success' }}"
                            aria-pressed="{{ $stockCondition === Transfer::CONDITION_GOOD ? 'true' : 'false' }}">
                        <i class="bi bi-check-circle"></i> Barang Baik
                    </button>
                    <button type="button" wire:click="selectStockCondition('{{ Transfer::CONDITION_BREAKAGE }}')"
                            class="btn {{ $stockCondition === Transfer::CONDITION_BREAKAGE ? 'btn-warning active' : 'btn-outline-warning' }}"
                            aria-pressed="{{ $stockCondition === Transfer::CONDITION_BREAKAGE ? 'true' : 'false' }}">
                        <i class="bi bi-exclamation-triangle"></i> Barang Rusak
                    </button>
                </div>
            @endif
        </div>
    </div>

    @if($confirmConditionChange)
        <div class="modal fade show d-block" style="background: rgba(0,0,0,0.5);" role="dialog" aria-modal="true">
            <div class="modal-dialog">
                <div class="modal-content">
                    <div class="modal-header bg-warning"><h5 class="modal-title">Konfirmasi Perubahan Kondisi Barang</h5></div>
                    <div class="modal-body">Mengubah kondisi barang akan menghapus seluruh baris produk yang telah dimasukkan. Lanjutkan?</div>
                    <div class="modal-footer">
                        <button type="button" wire:click="cancelConditionChange" class="btn btn-secondary">Batal</button>
                        <button type="button" wire:click="applyConditionChange" class="btn btn-warning">Ya, Ganti &amp; Hapus Baris</button>
                    </div>
                </div>
            </div>
        </div>
    @endif

    <div class="row mt-3">
        <div class="col-md-8 form-group">
            <label for="v3-scan-input">Scan Barcode / Nomor Seri</label>
            <div class="input-group">
                <div class="input-group-prepend">
                    <span class="input-group-text"><i class="bi bi-upc-scan text-primary"></i></span>
                </div>
                <input id="v3-scan-input" type="text" class="form-control" autocomplete="off" autofocus
                       wire:ignore data-v3-transfer-scan
                       placeholder="Scan barcode produk, barcode konversi, atau nomor seri">
                <div class="input-group-append">
                    <button type="button" class="btn btn-outline-primary" wire:click="openSearchModal" data-v3-open-search>
                        <i class="bi bi-search"></i> Cari Produk
                    </button>
                </div>
            </div>
            <small class="form-text text-muted">Pindai lalu tekan Enter. Hanya barcode produk, barcode konversi, dan nomor seri yang dicocokkan persis.</small>
            @if($scanMessage)
                <small id="v3-scan-feedback" role="status" aria-live="polite"
                       class="form-text font-weight-bold text-{{ $scanMessageLevel === 'success' ? 'success' : ($scanMessageLevel === 'warning' ? 'warning' : 'danger') }}">
                    {{ $scanMessage }}
                </small>
            @endif
            <div id="v3-scan-queue-status" wire:ignore aria-live="assertive"></div>
        </div>
    </div>

    @if($candidates !== [])
        <div class="list-group mb-3" role="listbox" aria-label="Pilih produk atau nomor seri" data-v3-candidates="{{ $pendingScanToken }}">
            <div class="list-group-item list-group-item-warning">Beberapa hasil cocok dengan pindaian. Pilih salah satu atau batalkan pindaian ini:</div>
            @foreach($candidates as $index => $candidate)
                <button type="button" class="list-group-item list-group-item-action" wire:key="v3-candidate-{{ $pendingScanToken }}-{{ $index }}"
                        data-v3-candidate="{{ $index }}">
                    {{ $candidate['description'] }}
                </button>
            @endforeach
            <button type="button" class="list-group-item list-group-item-action text-danger" data-v3-candidate-cancel>
                Batalkan Pindaian
            </button>
        </div>
    @endif

    <table class="table table-bordered table-sm">
        <thead>
        <tr>
            <th>Produk</th>
            <th style="width: 14%">Jumlah</th>
            <th>Nomor Seri</th>
            <th style="width: 5%"></th>
        </tr>
        </thead>
        <tbody>
        @forelse($rows as $index => $row)
            <tr wire:key="v3-row-{{ $row['product_id'] }}">
                <td>{{ $row['product_name'] }} <small class="text-muted d-block">{{ $row['product_code'] }}</small></td>
                <td>
                    {{-- Server-rendered, not wire:model: on the app layout a second
                         Alpine instance (resources/js/bootstrap.js) leaves a
                         wire:model input showing a stale value after a scan changes
                         quantity. Keying by the value replaces the input whenever
                         the server quantity changes; edits go through setQuantity. --}}
                    <input type="number" min="1" step="1" class="form-control form-control-sm"
                           wire:key="v3-qty-{{ $row['product_id'] }}-{{ $row['quantity'] }}"
                           value="{{ $row['quantity'] }}"
                           data-v3-quantity="{{ $index }}"
                           wire:change="setQuantity({{ $index }}, $event.target.value)">
                </td>
                <td>
                    @if($row['serialized'])
                        @foreach($row['serials'] as $serial)
                            <span class="badge badge-light border mr-1">
                                {{ $serial['serial_number'] }}
                                <a href="#" class="text-danger ml-1" wire:click.prevent="removeSerial({{ $index }}, {{ $serial['id'] }})" title="Hapus">&times;</a>
                            </span>
                        @endforeach
                        @php $selected = count($row['serials']); @endphp
                        <small class="d-block {{ $selected === (int) $row['quantity'] ? 'text-success' : 'text-warning' }}">
                            {{ $selected }} dari {{ (int) $row['quantity'] }} nomor seri dipilih
                        </small>
                    @else
                        -
                    @endif
                </td>
                <td class="text-center">
                    <button type="button" class="btn btn-sm btn-outline-danger" wire:click="removeRow({{ $index }})" title="Hapus baris">&times;</button>
                </td>
            </tr>
        @empty
            <tr><td colspan="4" class="text-center text-muted">Belum ada barang. Cari atau scan produk untuk menambahkan.</td></tr>
        @endforelse
        </tbody>
    </table>

    <div class="text-right mt-3">
        <button type="button" data-v3-action="saveDraft" class="btn btn-secondary">
            Simpan Draf <i class="bi bi-save"></i>
        </button>
        <button type="button" data-v3-action="submitForApproval" class="btn btn-primary">
            Ajukan Persetujuan <i class="bi bi-check"></i>
        </button>
    </div>

    {{-- Modal: Product Search Dialog (same layout as the legacy transfer search modal) --}}
    @if($showSearchModal)
        <div class="modal fade show d-block" tabindex="-1" role="dialog" aria-modal="true" aria-labelledby="v3ProductSearchModalTitle"
             data-v3-search-modal wire:keydown.escape="closeSearchModal"
             style="background: rgba(0,0,0,0.5); z-index: 1060;">
            <div class="modal-dialog modal-lg" role="document">
                <div class="modal-content">
                    <div class="modal-header">
                        <h5 class="modal-title" id="v3ProductSearchModalTitle"><i class="bi bi-search mr-1"></i> Cari Produk (Stok Dikelola)</h5>
                        <button type="button" class="close" wire:click="closeSearchModal" aria-label="Tutup">
                            <span aria-hidden="true">&times;</span>
                        </button>
                    </div>
                    <div class="modal-body">
                        <div class="form-group">
                            <input type="text" class="form-control form-control-lg" autocomplete="off"
                                   data-v3-search-input
                                   placeholder="Ketik nama, kode, barcode, kategori, atau merek produk..."
                                   wire:model.live.debounce.300ms="searchQuery"
                                   wire:keydown.enter.prevent="searchProducts">
                        </div>

                        <div class="list-group list-group-flush border rounded" style="max-height: 350px; overflow-y: auto;">
                            @forelse($searchResults as $index => $result)
                                <button type="button" class="list-group-item list-group-item-action d-flex justify-content-between align-items-center"
                                        wire:key="v3-search-{{ $result['id'] }}"
                                        wire:click="selectSearchResult({{ $index }})"
                                        wire:loading.attr="disabled"
                                        wire:target="selectSearchResult">
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
                                        <span class="btn btn-sm btn-primary">Pilih</span>
                                    </div>
                                </button>
                            @empty
                                <div class="text-center py-4 text-muted">
                                    @if(trim($searchQuery) === '')
                                        <span>Ketik kata kunci untuk mencari produk.</span>
                                    @else
                                        <span>Produk tidak ditemukan atau stok kosong.</span>
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

    <script>
        if (!window.V3TransferScanCoordinator) {
            {!! file_get_contents(resource_path('js/transfer/v3-scan-coordinator.js')) !!}
        }
        if (!window.V3TransferLivewireTransport) {
            {!! file_get_contents(resource_path('js/transfer/v3-livewire-transport.js')) !!}
        }

        // DOM wiring for the v3 scan coordinator (core logic lives in
        // resources/js/transfer/v3-scan-coordinator.js and is unit tested).
        if (!window.__v3TransferScanBindingInitialized) {
            window.__v3TransferScanBindingInitialized = true;

            (function () {
                const Core = window.V3TransferScanCoordinator;
                let transport = null;

                function scanInput() {
                    return document.querySelector('[data-v3-transfer-scan]');
                }

                function wire() {
                    const input = scanInput();
                    const root = input ? input.closest('[wire\\:id]') : null;
                    if (!root || typeof Livewire === 'undefined') {
                        return null;
                    }
                    try {
                        return Livewire.find(root.getAttribute('wire:id'));
                    } catch (error) {
                        return null;
                    }
                }

                function componentId() {
                    const input = scanInput();
                    const root = input ? input.closest('[wire\\:id]') : null;
                    return root ? root.getAttribute('wire:id') : null;
                }

                // Livewire integration (hook ordering, popup suppression,
                // unusable-transport detection) lives in, and is tested via,
                // resources/js/transfer/v3-livewire-transport.js.
                function call(method, ...args) {
                    return transport
                        ? transport.call(method, ...args)
                        : Promise.reject(new Error('livewire-not-ready'));
                }

                function startTransport() {
                    if (transport) {
                        return;
                    }
                    transport = window.V3TransferLivewireTransport.create({
                        livewire: Livewire,
                        host: window,
                        getComponent: wire,
                        getComponentId: componentId,
                        onFatal: function () {
                            if (window.__v3TransferScanCoordinator) {
                                window.__v3TransferScanCoordinator.markFatal();
                            }
                        },
                    });
                }
                if (typeof Livewire !== 'undefined' && typeof Livewire.hook === 'function') {
                    startTransport();
                } else {
                    document.addEventListener('livewire:init', startTransport, { once: true });
                }

                function isEditingElsewhere() {
                    if (document.querySelector('[data-v3-search-modal]')) {
                        return true;
                    }
                    const active = document.activeElement;
                    if (!active || active === document.body || active === scanInput()) {
                        return false;
                    }
                    return active.isContentEditable || ['INPUT', 'TEXTAREA', 'SELECT'].includes(active.tagName);
                }

                function focusScan(force) {
                    setTimeout(function () {
                        const input = scanInput();
                        if (input && (force || !isEditingElsewhere())) {
                            input.focus();
                        }
                    }, 30);
                }

                function render(state) {
                    const panel = document.getElementById('v3-scan-queue-status');
                    if (panel) {
                        panel.replaceChildren();
                        const text = state.message || state.notice;
                        if (text) {
                            const box = document.createElement('div');
                            box.className = 'alert py-2 px-3 mt-2 mb-0 ' + (state.phase === 'failed' ? 'alert-danger' : (state.phase === 'awaiting_choice' || state.notice ? 'alert-warning' : 'alert-info'));
                            box.setAttribute('role', state.phase === 'failed' ? 'alert' : 'status');
                            const message = document.createElement('div');
                            message.textContent = state.message || '';
                            if (state.message) {
                                box.appendChild(message);
                            }
                            if (state.notice && state.notice !== state.message) {
                                const notice = document.createElement('div');
                                notice.className = 'font-weight-bold';
                                notice.textContent = state.notice;
                                box.appendChild(notice);
                            }
                            if (state.canRetry) {
                                const actions = document.createElement('div');
                                actions.className = 'mt-2';
                                actions.innerHTML = '<button type="button" class="btn btn-sm btn-danger mr-2" data-v3-queue-retry>Coba Lagi</button>'
                                    + '<button type="button" class="btn btn-sm btn-outline-secondary" data-v3-queue-cancel>'
                                    + (state.failure && state.failure.kind === 'scan' ? 'Batalkan Pindaian' : 'Batal') + '</button>';
                                box.appendChild(actions);
                            }
                            panel.appendChild(box);
                        }
                    }
                    document.querySelectorAll('[data-v3-action]').forEach(function (button) {
                        button.disabled = state.actionRunning || state.intakeFrozen;
                    });
                    const input = scanInput();
                    if (input) {
                        input.classList.toggle('is-invalid', state.phase === 'failed' || state.fatal);
                    }
                }

                const coordinator = Core.create({
                    call: call,
                    render: render,
                    onScanSettled: function () { focusScan(false); },
                });
                window.__v3TransferScanCoordinator = coordinator;

                // Capture phase so the terminator never reaches form/submit handlers.
                document.addEventListener('keydown', function (event) {
                    const input = event.target;
                    if (!input || !input.matches || !input.matches('[data-v3-transfer-scan]') || !Core.isScanTerminator(event)) {
                        return;
                    }
                    event.preventDefault();
                    event.stopPropagation();
                    const value = input.value;
                    input.value = '';
                    coordinator.enqueue(value);
                }, true);

                // Keyboard wedges that deliver CR/LF as characters (e.g. paste).
                document.addEventListener('input', function (event) {
                    const input = event.target;
                    if (!input || !input.matches || !input.matches('[data-v3-transfer-scan]') || !/[\r\n]/.test(input.value)) {
                        return;
                    }
                    const split = Core.splitScanBuffer(input.value);
                    input.value = split.rest;
                    split.codes.forEach(coordinator.enqueue);
                }, true);

                document.addEventListener('click', function (event) {
                    const target = event.target && event.target.closest ? event.target : null;
                    if (!target) {
                        return;
                    }
                    const candidate = target.closest('[data-v3-candidate]');
                    if (candidate) {
                        event.preventDefault();
                        coordinator.choose(parseInt(candidate.getAttribute('data-v3-candidate'), 10));
                        return;
                    }
                    if (target.closest('[data-v3-candidate-cancel]')) {
                        event.preventDefault();
                        coordinator.cancelChoice();
                        return;
                    }
                    if (target.closest('[data-v3-queue-retry]')) {
                        event.preventDefault();
                        coordinator.retry();
                        return;
                    }
                    if (target.closest('[data-v3-queue-cancel]')) {
                        event.preventDefault();
                        coordinator.cancelFailed();
                        return;
                    }
                    const action = target.closest('[data-v3-action]');
                    if (action) {
                        event.preventDefault();
                        const method = action.getAttribute('data-v3-action');
                        // Routed through `call` so request failures settle it; a
                        // clean result with no errorMessage means the server
                        // is redirecting, so scan intake stays frozen.
                        coordinator.requestAction(async function () {
                            await call(method);
                            const component = wire();
                            return { keepFrozen: !(component && component.get('errorMessage')) };
                        });
                    }
                });

                window.addEventListener('v3-transfer-scan-focus', function (event) {
                    const detail = event && event.detail ? (Array.isArray(event.detail) ? event.detail[0] : event.detail) : {};
                    focusScan(Boolean(detail && detail.force));
                });

                window.addEventListener('v3-transfer-search-opened', function () {
                    setTimeout(function () {
                        const input = document.querySelector('[data-v3-search-input]');
                        if (input) {
                            input.focus();
                        }
                    }, 50);
                });
            })();
        }
    </script>
</div>

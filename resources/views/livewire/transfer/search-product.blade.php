<div class="position-relative">
    <div class="card mb-0 border-0 shadow-sm">
        <div class="card-body">
            <div class="form-group mb-0">
                <div class="input-group">
                    <div class="input-group-prepend">
                        <div class="input-group-text">
                            <i class="bi bi-upc-scan text-primary"></i>
                        </div>
                    </div>
                    <input
                        wire:keydown.escape="resetQuery"
                        wire:model.live.debounce.500ms="query"
                        id="transfer-barcode-scan"
                        type="text"
                        class="form-control"
                        placeholder="Pindai barcode atau ketik nama/kode produk...."
                        @unless($locationId) disabled @endunless
                    >
                    <div class="input-group-append">
                        <button class="btn btn-outline-primary" type="button" id="transfer-scan-button" @unless($locationId) disabled @endunless>
                            Pindai
                        </button>
                    </div>
                </div>
                <div id="transfer-scan-error" class="alert alert-danger py-2 px-3 mt-2 mb-0 d-none" role="alert" aria-live="assertive"></div>
                <div class="d-flex justify-content-between align-items-center mt-2">
                    @if($locationId)
                        <small class="form-text text-muted mb-0">Tekan Enter untuk memindai barcode atau serial number.</small>
                    @else
                        <small class="form-text text-danger mb-0">Silakan pilih Lokasi Asal terlebih dahulu untuk mulai memasukkan produk.</small>
                    @endif
                </div>
            </div>
        </div>
    </div>

    <div wire:loading class="card position-absolute mt-1 border-0" style="z-index: 1;left: 0;right: 0;">
        <div class="card-body shadow">
            <div class="d-flex justify-content-center">
                <div class="spinner-border text-primary" role="status">
                    <span class="sr-only">Loading...</span>
                </div>
            </div>
        </div>
    </div>

    @if(!empty($query))
        <div wire:click="resetQuery" class="position-fixed w-100 h-100" style="left: 0; top: 0; right: 0; bottom: 0;z-index: 1;"></div>
        @if($search_results->isNotEmpty())
            <div class="card position-absolute mt-1" style="z-index: 2;left: 0;right: 0;border: 0;">
                <div class="card-body shadow">
                    <ul class="list-group list-group-flush">
                        @foreach($search_results as $result)
                            @php
                                $resultName = is_array($result) ? $result['product_name'] : $result->product_name;
                                $resultCode = is_array($result) ? $result['product_code'] : $result->product_code;
                                $resultJson = is_array($result) ? json_encode($result) : $result;
                            @endphp
                            <li class="list-group-item list-group-item-action">
                                <a wire:click="resetQuery" wire:click.prevent="selectProduct({{ $resultJson }})" href="#">
                                    {{ $resultName }} | {{ $resultCode }}
                                </a>
                            </li>
                        @endforeach
                        @if($search_results->count() >= $how_many)
                            <li class="list-group-item list-group-item-action text-center">
                                <a wire:click.prevent="loadMore" class="btn btn-primary btn-sm" href="#">
                                    Load More <i class="bi bi-arrow-down-circle"></i>
                                </a>
                            </li>
                        @endif
                    </ul>
                </div>
            </div>
        @else
            <div class="card position-absolute mt-1 border-0" style="z-index: 1;left: 0;right: 0;">
                <div class="card-body shadow">
                    <div class="alert alert-warning mb-0">
                        Produk tidak ditemukan....
                    </div>
                </div>
            </div>
        @endif
    @endif

    <script>
        document.addEventListener('livewire:init', function () {
            window.addEventListener('restore-scanner-focus', function () {
                setTimeout(function () {
                    const scanInput = document.getElementById('transfer-barcode-scan');
                    if (scanInput) {
                        scanInput.focus();
                        if (typeof scanInput.select === 'function') {
                            scanInput.select();
                        }
                    }
                }, 50);
            });

            window.addEventListener('select-scan-input', function () {
                setTimeout(function () {
                    const scanInput = document.getElementById('transfer-barcode-scan');
                    if (scanInput) {
                        scanInput.focus();
                        if (typeof scanInput.select === 'function') {
                            scanInput.select();
                        }
                    }
                }, 50);
            });

            window.addEventListener('scanFailed', function (event) {
                const message = (event && event.detail && typeof event.detail === 'string')
                    ? event.detail
                    : (Array.isArray(event.detail) ? event.detail[0] : 'Pindaian ditolak.');
                const scanError = document.getElementById('transfer-scan-error');
                const scanInput = document.getElementById('transfer-barcode-scan');
                if (scanInput) {
                    scanInput.classList.add('is-invalid');
                }
                if (scanError) {
                    scanError.textContent = message;
                    scanError.classList.remove('d-none');
                }
            });
        });

        // =========================================================================
        // Duplicate-guarded, morph-safe FIFO scan controller for Stock Transfer
        // =========================================================================
        if (!window.__transferScanControllerInitialized) {
            window.__transferScanControllerInitialized = true;

            (function () {
                const queue = [];
                let busy = false;
                const MAX_LOOKUP_ATTEMPTS = 20;

                function generateToken() {
                    return 'txscan-' + Date.now() + '-' + Math.random().toString(36).substring(2, 9);
                }

                function getElements() {
                    const scanInput = document.getElementById('transfer-barcode-scan');
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
                                    console.error('Transfer scanner: komponen tidak ditemukan. Scan discarded:', next.value);
                                    showScanFeedback('Gagal memproses pindaian "' + next.value + '": komponen tidak ditemukan. Muat ulang halaman.');
                                    continue;
                                }
                                await new Promise((resolve) => setTimeout(resolve, 100));
                                continue;
                            }

                            queue.shift();

                            // Await both the Livewire call AND the downstream table mutation acknowledgment (transfer:scan-processed)
                            const processedPromise = new Promise((resolve) => {
                                let timer = null;
                                const handler = function (event) {
                                    const eventToken = (event && event.detail && typeof event.detail === 'object')
                                        ? (event.detail.token ?? (event.detail[0] ? event.detail[0].token : null))
                                        : null;

                                    if (eventToken === next.token) {
                                        window.removeEventListener('transfer:scan-processed', handler);
                                        if (timer) clearTimeout(timer);
                                        resolve();
                                    }
                                };

                                window.addEventListener('transfer:scan-processed', handler);
                                timer = setTimeout(() => {
                                    window.removeEventListener('transfer:scan-processed', handler);
                                    resolve();
                                }, 4000);
                            });

                            try {
                                await component.call('scanBarcode', next.value, next.token);
                                await processedPromise;
                            } catch (error) {
                                console.error('Transfer scanner: scanBarcode request failed; result unknown, not retried automatically.', error, next.value);
                                showScanFeedback('Status tidak diketahui untuk pindaian "' + next.value + '". Periksa daftar produk terpilih, lalu pindai ulang bila belum bertambah.');
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
                        component.set('query', '', false);
                    }

                    if (value === '') {
                        return;
                    }
                    clearScanFeedback();
                    const token = generateToken();
                    queue.push({ value: value, token: token, attempts: 0 });
                    drain();
                }

                // Delegated event listener for scan input keydown (guarded and morph-safe)
                document.addEventListener('keydown', function (event) {
                    if (event.target && event.target.id === 'transfer-barcode-scan') {
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
                        return;
                    }
                });
            })();
        }
    </script>
</div>

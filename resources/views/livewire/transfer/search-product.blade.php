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
                        wire:keydown.enter.prevent="scanBarcode($event.target.value)"
                        wire:keydown.escape="resetQuery"
                        wire:model.live.debounce.500ms="query"
                        id="transfer-barcode-scan"
                        type="text"
                        class="form-control"
                        placeholder="Pindai barcode atau ketik nama/kode produk...."
                        @unless($locationId) disabled @endunless
                    >
                    <div class="input-group-append">
                        <button wire:click="scanBarcode(query)" class="btn btn-outline-primary" type="button" @unless($locationId) disabled @endunless>
                            Pindai
                        </button>
                    </div>
                </div>
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
            const scanInput = document.getElementById('transfer-barcode-scan');
            if (!scanInput) {
                return;
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

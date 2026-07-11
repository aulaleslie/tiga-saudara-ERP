<div>
    <div class="row">
        <!-- Sidebar / Selection List -->
        <div class="col-md-5 col-lg-4">
            <div class="card">
                <div class="card-header bg-primary text-white d-flex justify-content-between align-items-center">
                    <h5 class="mb-0">Pilih Produk</h5>
                </div>
                <div class="card-body p-0">
                    <div class="p-3 border-bottom">
                        <input type="text" class="form-control mb-2" wire:model.live.debounce.300ms="searchQuery" placeholder="Cari Nama/Kode Produk...">
                        <div class="custom-control custom-switch">
                            <input type="checkbox" class="custom-control-input" id="uninitializedOnly" wire:model.live="filterUninitializedOnly">
                            <label class="custom-control-label" for="uninitializedOnly">Tampilkan Hanya yang Belum Punya Barcode</label>
                        </div>
                    </div>
                    
                    <div class="list-group list-group-flush" style="max-height: 500px; overflow-y: auto;">
                        @forelse($products as $product)
                            <button type="button" 
                                    class="list-group-item list-group-item-action @if($selectedProductId === $product->id) active @endif"
                                    wire:click="selectProduct({{ $product->id }})">
                                <div class="d-flex w-100 justify-content-between">
                                    <h6 class="mb-1 text-truncate">{{ $product->product_name }}</h6>
                                    @if(empty($product->barcode))
                                        <span class="badge badge-warning">Belum</span>
                                    @else
                                        <span class="badge badge-success">Sudah</span>
                                    @endif
                                </div>
                                <small>{{ $product->product_code }} | Unit: {{ $product->baseUnit ? $product->baseUnit->name : 'N/A' }}</small>
                            </button>
                        @empty
                            <div class="p-3 text-center text-muted">Tidak ada produk ditemukan.</div>
                        @endforelse
                    </div>
                    <div class="p-2 border-top">
                        {{ $products->links() }}
                    </div>
                </div>
            </div>

            <!-- Recent Successes -->
            @if(count($recentSuccesses) > 0)
            <div class="card mt-3">
                <div class="card-header bg-success text-white">
                    <h6 class="mb-0">Aktivitas Sesi Ini ({{ $sessionSavedCount }} Disimpan)</h6>
                </div>
                <ul class="list-group list-group-flush">
                    @foreach($recentSuccesses as $success)
                        <li class="list-group-item py-1 text-muted small">
                            <i class="bi bi-check-circle-fill text-success mr-1"></i>
                            <strong>{{ $success['barcode'] }}</strong> 
                            &rarr; {{ Str::limit($success['name'], 20) }}
                            <span class="float-right">{{ $success['time'] }}</span>
                        </li>
                    @endforeach
                </ul>
            </div>
            @endif
        </div>

        <!-- Main Workspace -->
        <div class="col-md-7 col-lg-8">
            <div class="card">
                <div class="card-header">
                    <h5 class="mb-0">Workspace Inisialisasi</h5>
                </div>
                <div class="card-body">
                    
                    @if($currentState === 'SEARCHING')
                        <div class="text-center py-5 text-muted">
                            <i class="bi bi-upc-scan" style="font-size: 4rem;"></i>
                            <p class="mt-3">Silakan pilih produk dari daftar di sebelah kiri untuk memulai.</p>
                        </div>
                    @else
                        <!-- Selected Product Info -->
                        <div class="alert alert-info">
                            <div class="d-flex justify-content-between align-items-center">
                                <div>
                                    <h5 class="alert-heading mb-1">{{ $selectedProductName }}</h5>
                                    <p class="mb-0">Kode: <strong>{{ $selectedProductCode }}</strong> | Unit: <strong>{{ $selectedProductUnit }}</strong></p>
                                </div>
                                <div>
                                    <button class="btn btn-sm btn-outline-secondary" wire:click="cancelSelection">Batal</button>
                                </div>
                            </div>
                            @if(!empty($originalBarcode))
                                <hr>
                                <p class="mb-0 text-warning"><i class="bi bi-exclamation-triangle-fill"></i> Produk ini sudah memiliki barcode: <strong>{{ $originalBarcode }}</strong>. Jika Anda menyimpan barcode baru, barcode lama akan ditimpa (Replacement).</p>
                            @endif
                        </div>

                        <!-- Scanner Input -->
                        <div class="form-group mt-4">
                            <label for="scannerInput" class="font-weight-bold">Scan Barcode / Input Manual</label>
                            
                            @if($currentState === 'READY_TO_SCAN')
                                <!-- Form wrapper prevents page reload on enter -->
                                <form onsubmit="event.preventDefault(); window.Livewire.find('{{ $_instance->getId() }}').handleScan(document.getElementById('scannerInput').value);">
                                    <div class="input-group input-group-lg">
                                        <div class="input-group-prepend">
                                            <span class="input-group-text"><i class="bi bi-upc-scan"></i></span>
                                        </div>
                                        <input type="text" id="scannerInput" class="form-control" placeholder="Scan sekarang..." autocomplete="off">
                                    </div>
                                    <small class="form-text text-muted mt-2">Pastikan kursor berada di kotak ini saat melakukan scan.</small>
                                </form>
                            @endif

                            @if($candidateError)
                                <div class="alert alert-danger mt-3">
                                    <i class="bi bi-x-circle-fill"></i> {{ $candidateError }}
                                </div>
                            @endif
                        </div>

                        <!-- Review State -->
                        @if($currentState === 'REVIEW' || $currentState === 'SAVING')
                            <div class="card bg-light mt-4">
                                <div class="card-body text-center">
                                    <h6 class="text-uppercase text-muted">Review Barcode</h6>
                                    <h2 class="display-4 font-monospace">{{ $candidateBarcode }}</h2>
                                    
                                    <div class="mt-3 mb-4">
                                        @php
                                            $previewSvg = '';
                                            $previewFailed = false;
                                            try {
                                                $previewSvg = DNS1D::getBarcodeSVG($candidateBarcode, 'C128', 2, 60, 'black', true);
                                            } catch (\Throwable $e) {
                                                $previewFailed = true;
                                            }
                                        @endphp
                                        <!-- Code 128 Preview using Milon/Barcode (Requires DNS1D facade or similar) -->
                                        @if($previewFailed)
                                            <div class="text-danger small">Tidak dapat merender preview barcode.</div>
                                        @else
                                            {!! $previewSvg !!}
                                        @endif
                                    </div>
                                    
                                    <hr>

                                    @if($originalBarcode)
                                        <div class="alert alert-warning text-left mb-4">
                                            <strong>Perhatian!</strong> Anda akan mengganti barcode <code>{{ $originalBarcode }}</code> menjadi <code>{{ $candidateBarcode }}</code>.
                                        </div>
                                    @endif
                                    
                                    <div class="d-flex justify-content-center gap-3">
                                        <button class="btn btn-secondary mr-2" wire:click="ulangiScan" @if($currentState === 'SAVING') disabled @endif>Ulangi Scan</button>
                                        <button class="btn {{ $originalBarcode ? 'btn-warning' : 'btn-success' }} ml-2" wire:click="save" id="btnConfirmSave" @if($currentState === 'SAVING' || $previewFailed) disabled @endif>
                                            @if($currentState === 'SAVING')
                                                <span class="spinner-border spinner-border-sm" role="status" aria-hidden="true"></span> Menyimpan...
                                            @else
                                                <i class="bi bi-check-circle"></i> Konfirmasi {{ $originalBarcode ? 'Penggantian' : 'Simpan' }}
                                            @endif
                                        </button>
                                    </div>
                                </div>
                            </div>
                        @endif

                    @endif

                </div>
            </div>
        </div>
    </div>
    
    @script
    <script>
        $wire.on('product-selected', () => {
            setTimeout(() => {
                const scannerInput = document.getElementById('scannerInput');
                if(scannerInput) {
                    scannerInput.value = '';
                    scannerInput.focus();
                }
            }, 100);
        });

        $wire.on('scan-error', () => {
            setTimeout(() => {
                const scannerInput = document.getElementById('scannerInput');
                if(scannerInput) {
                    scannerInput.select();
                    scannerInput.focus();
                }
            }, 100);
        });

        $wire.on('review-ready', () => {
            setTimeout(() => {
                const btnConfirmSave = document.getElementById('btnConfirmSave');
                if(btnConfirmSave && !btnConfirmSave.disabled) {
                    btnConfirmSave.focus();
                }
            }, 100);
        });

        $wire.on('save-success', () => {
            setTimeout(() => {
                const searchInput = document.querySelector('input[wire\\:model\\.live\\.debounce\\.300ms="searchQuery"]');
                if(searchInput) {
                    searchInput.value = '';
                    searchInput.focus();
                }
            }, 100);
        });

        $wire.on('selection-cancelled', () => {
            setTimeout(() => {
                const searchInput = document.querySelector('input[wire\\:model\\.live\\.debounce\\.300ms="searchQuery"]');
                if(searchInput) {
                    searchInput.focus();
                }
            }, 100);
        });
    </script>
    @endscript
</div>

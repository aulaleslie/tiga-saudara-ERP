<div class="position-relative">
    <div class="card mb-0 border-0 shadow-sm">
        <div class="card-body">
            <div class="form-group mb-0">
                <div class="input-group">
                    <span class="input-group-text bg-white border-end-0">
                        <i class="bi bi-search text-primary"></i>
                    </span>
                    <input
                        wire:keydown.escape="resetQuery"
                        wire:keydown.arrow-down.prevent="highlightNext"
                        wire:keydown.arrow-up.prevent="highlightPrevious"
                        wire:keydown.enter.prevent="selectExactMatch"
                        wire:model.debounce.500ms="query"
                        type="text"
                        class="form-control border-start-0"
                        placeholder="Masukkan nomor referensi penjualan..."
                    >
                </div>
            </div>
        </div>
    </div>

    <div wire:loading class="card position-absolute mt-1 border-0" style="z-index: 3; left: 0; right: 0;">
        <div class="card-body shadow-sm">
            <div class="d-flex justify-content-center">
                <div class="spinner-border text-primary" role="status">
                    <span class="sr-only">Memuat...</span>
                </div>
            </div>
        </div>
    </div>

    @if(!empty($query))
        <div wire:click="resetQuery" class="position-fixed w-100 h-100" style="left: 0; top: 0; right: 0; bottom: 0; z-index: 2;"></div>
        @if($searchResults->isNotEmpty())
            <div class="card position-absolute mt-1" style="z-index: 4; left: 0; right: 0; border: 0;">
                <div class="card-body shadow-sm">
                    <ul class="list-group list-group-flush">
                        @foreach ($searchResults as $index => $result)
                            <li class="list-group-item list-group-item-action d-flex justify-content-between align-items-center {{ $index === $highlightedIndex ? 'active' : '' }}"
                                wire:key="sale-reference-result-{{ $result->id }}">
                                <a wire:click.prevent="selectSale({{ $result->id }})" href="#" class="text-decoration-none w-100 {{ $index === $highlightedIndex ? 'text-white' : '' }}">
                                    <div class="fw-semibold d-flex align-items-center justify-content-between">
                                        <span>{{ $result->reference }}</span>
                                        <span class="badge {{ $index === $highlightedIndex ? 'bg-white text-primary' : 'bg-light text-muted' }} text-uppercase">{{ $result->status }}</span>
                                    </div>
                                    <div class="small {{ $index === $highlightedIndex ? 'text-white-50' : 'text-muted' }}">{{ $result->customer_name ?? 'Tanpa pelanggan' }}</div>
                                    <div class="small mt-1 {{ $index === $highlightedIndex ? 'text-white-50' : 'text-secondary' }}">
                                        {{ $result->returnable_lines }} produk dapat diretur &mdash; total {{ $result->total_available_quantity }} qty
                                        @if($result->requires_serials)
                                            <span class="badge {{ $index === $highlightedIndex ? 'bg-info text-dark' : 'bg-info text-dark' }} ms-1">Serial</span>
                                        @endif
                                        @if(($result->bundle_lines ?? 0) > 0)
                                            <span class="badge {{ $index === $highlightedIndex ? 'bg-secondary text-light' : 'bg-secondary' }} ms-1">Bundle</span>
                                        @endif
                                    </div>
                                </a>
                            </li>
                        @endforeach
                        @if($searchResults->count() >= $howMany)
                            <li class="list-group-item text-center">
                                <a wire:click.prevent="loadMore" class="btn btn-outline-primary btn-sm" href="#">
                                    Muat lebih banyak <i class="bi bi-arrow-down-circle"></i>
                                </a>
                            </li>
                        @endif
                    </ul>
                </div>
            </div>
        @else
            <div class="card position-absolute mt-1 border-0" style="z-index: 4; left: 0; right: 0;">
                <div class="card-body shadow-sm">
                    <div class="alert alert-warning mb-0">Referensi tidak ditemukan.</div>
                </div>
            </div>
        @endif
    @endif
</div>

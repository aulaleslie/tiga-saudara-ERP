<div class="position-relative" x-data x-init="$nextTick(() => { $refs.searchInput?.focus(); })">
    <div class="card mb-0 border-0 shadow-sm">
        <div class="card-body">
            <div class="form-group mb-0">
                <div class="input-group">
                    <div class="input-group-prepend">
                        <div class="input-group-text">
                            <i class="bi bi-search text-primary"></i>
                        </div>
                    </div>
                    <input
                        x-ref="searchInput"
                        wire:keydown.escape="resetQuery"
                        wire:keydown.enter="showSearchResults"
                        wire:model.live.debounce.500ms="query"
                        type="text"
                        class="form-control"
                        placeholder="Ketik nama produk atau kode produk...."
                        autocomplete="off"
                        autofocus
                    >
                </div>
            </div>
        </div>
    </div>

    <div wire:loading.class.remove="d-none"
         wire:loading
         class="card position-absolute mt-1 border-0 d-none"
         style="z-index: 1050; left: 0; right: 0;">
        <div class="card-body shadow">
            <div class="d-flex justify-content-center">
                <div class="spinner-border text-primary" role="status">
                    <span class="sr-only">Memuat...</span>
                </div>
            </div>
        </div>
    </div>

    @if(!empty($query))
        <div wire:click="resetQuery" class="position-fixed w-100 h-100"
             style="left: 0; top: 0; right: 0; bottom: 0; z-index: 1;"></div>

        @if($search_results->isNotEmpty())
            <div class="card position-absolute mt-1" style="z-index: 2; left: 0; right: 0; border: 0;">
                <div class="card-body shadow">
                    <ul class="list-group list-group-flush">
                        @foreach($search_results as $index => $result)
                            <li class="list-group-item list-group-item-action" wire:key="search-{{ $result['id'] ?? '0' }}-{{ $index }}">
                                <a href="#"
                                   wire:click.prevent="selectProduct(@js($result))"
                                   class="d-flex justify-content-between align-items-center">
                                    <div class="mr-2">
                                        <div class="d-flex align-items-center flex-wrap">
                                            <strong>{{ $result['product_name'] }}</strong>
                                            <span class="text-muted ml-1">| {{ $result['product_code'] }}</span>
                                            <span class="badge badge-light ml-2">{{ $result['product_unit'] }}</span>
                                        </div>
                                        <div class="text-muted small mt-1">Stok: {{ $result['product_quantity'] ?? 0 }}</div>
                                    </div>
                                </a>
                            </li>
                        @endforeach

                        @if($search_results->count() >= $how_many)
                            <li class="list-group-item list-group-item-action text-center">
                                <a wire:click.prevent="loadMore" class="btn btn-primary btn-sm" href="#">
                                    Memuat lebih <i class="bi bi-arrow-down-circle"></i>
                                </a>
                            </li>
                        @endif
                    </ul>
                </div>
            </div>
        @elseif(mb_strlen(trim($query)) >= 2)
            <div class="card position-absolute mt-1 border-0" style="z-index: 1; left: 0; right: 0;">
                <div class="card-body shadow">
                    <div class="alert alert-warning mb-0">
                        Produk tidak ditemukan....
                    </div>
                </div>
            </div>
        @endif
    @endif
</div>

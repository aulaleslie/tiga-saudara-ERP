<div class="position-relative">
    <div class="form-group mb-0">
        <label for="barcode-product-search">Cari Produk (Nama, SKU, Barcode)</label>
        <div class="input-group">
            <div class="input-group-prepend">
                <div class="input-group-text">
                    <i class="bi bi-search text-primary"></i>
                </div>
            </div>
            <input
                id="barcode-product-search"
                wire:keydown.escape="resetQuery"
                wire:keydown.enter="handleEnter"
                wire:model.live.debounce.500ms="query"
                type="text"
                class="form-control"
                placeholder="Ketik nama, SKU, atau scan barcode produk..."
                autocomplete="off"
            >
        </div>
    </div>

    @if(!empty($query))
        <div wire:click="resetQuery" class="position-fixed w-100 h-100"
             style="left: 0; top: 0; right: 0; bottom: 0; z-index: 1;"></div>

        @if(!empty($authorizationError))
            <div class="card position-absolute mt-1 border-0" style="z-index: 2; left: 0; right: 0;">
                <div class="card-body shadow">
                    <div class="alert alert-danger mb-0">{{ $authorizationError }}</div>
                </div>
            </div>
        @elseif($search_results->isNotEmpty())
            <div class="card position-absolute mt-1" style="z-index: 2; left: 0; right: 0; border: 0;">
                <div class="card-body shadow">
                    <ul class="list-group list-group-flush">
                        @foreach($search_results as $index => $result)
                            <li class="list-group-item list-group-item-action" wire:key="barcode-search-{{ $result['id'] }}-{{ $index }}">
                                <a href="#"
                                   wire:click.prevent="selectProduct(@js($result))"
                                   class="d-flex justify-content-between align-items-center text-decoration-none text-reset">
                                    <div class="mr-2">
                                        <div>
                                            <strong>{{ $result['product_name'] }}</strong>
                                            <span class="text-muted ml-1">| {{ $result['product_code'] }}</span>
                                        </div>
                                        <div class="small text-muted mt-1">
                                            @if(!empty($result['barcode']))
                                                <span>Barcode: <span class="font-weight-normal text-dark">{{ $result['barcode'] }}</span></span>
                                            @else
                                                <span class="text-danger">Barcode: (tidak ada)</span>
                                            @endif
                                            <span class="mx-1">•</span>
                                            <span>Satuan: {{ $result['product_unit'] }}</span>
                                        </div>
                                    </div>
                                    <div class="text-right ml-2 flex-shrink-0">
                                        @if($result['sale_price'] !== null)
                                            <div class="font-weight-bold text-primary">{{ $result['formatted_price'] }}</div>
                                        @else
                                            <span class="badge badge-warning text-dark font-weight-normal">Harga tidak tersedia</span>
                                        @endif
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
                    <div class="alert alert-warning mb-0">Produk tidak ditemukan....</div>
                </div>
            </div>
        @endif
    @endif
</div>

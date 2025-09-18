<div class="container py-3">
    <div class="row mb-3">
        <div class="col-12 d-flex justify-content-center">
            <img width="180" src="{{ asset('images/logo-dark.png') }}" alt="Logo">
        </div>
    </div>

    <div class="card border-0 shadow-sm mb-3 sticky-top-shadow bg-white">
        <div class="card-body">
            <div class="d-flex flex-wrap align-items-center gap-2">
                <div class="me-auto">
                    <h5 class="mb-0">Terminal Harga</h5>
                    <div class="text-muted small">Outlet: <strong>{{ $setting->company_name ?? ('#'.$setting->id) }}</strong></div>
                </div>
                <div class="w-100 mt-2"></div>
                <div class="input-group">
                    <span class="input-group-text"><i class="bi bi-upc-scan"></i></span>
                    <input
                        id="pp-search"
                        type="text"
                        class="form-control"
                        placeholder="Scan/ketik nama • brand • kategori • barcode • serial"
                        wire:model.debounce.350ms="q"
                        wire:keydown.enter="searchNow"
                        autocomplete="off"
                    >
                    @if($q !== '')
                        <button class="btn btn-outline-secondary" wire:click="$set('q','')" type="button">
                            Bersihkan
                        </button>
                    @endif
                </div>
                <div class="w-100 mt-1 scan-hint">Gunakan scanner (akhiri dengan Enter). Setelah pencarian, kursor otomatis kembali ke kotak ini.</div>
            </div>
        </div>
    </div>

    <div wire:loading.flex class="justify-content-center my-5">
        <div class="spinner-border" role="status" aria-hidden="true"></div>
    </div>

    <div wire:loading.remove>
        @if($products->count() === 0)
            <div class="alert alert-light border text-center">Tidak ada produk untuk kata kunci ini.</div>
        @else
            <div class="row row-cols-1 row-cols-sm-2 row-cols-lg-3 g-3">
                @foreach($products as $product)
                    <div class="col">
                        <div class="card product-card border-0 shadow-sm h-100">
                            <div class="card-body d-flex">
                                <div class="me-3" style="width:140px;">
                                    @php
                                        $img = method_exists($product, 'getFirstMediaUrl')
                                            ? $product->getFirstMediaUrl('products')
                                            : null;
                                    @endphp
                                    <img
                                        src="{{ $img ?: asset('images/fallback_product_image.png') }}"
                                        class="img-fluid border rounded"
                                        alt="product image">
                                </div>
                                <div class="flex-grow-1">
                                    <div class="fw-semibold mb-1">{{ $product->product_name }}</div>
                                    <div class="text-muted small mb-1">
                                        {{ $product->product_code }} @if($product->barcode) • {{ $product->barcode }} @endif
                                    </div>
                                    <div class="text-muted small mb-2">
                                        @if(optional($product->brand)->name)
                                            <span class="me-2"><i class="bi bi-tags"></i> {{ $product->brand->name }}</span>
                                        @endif
                                        @if(optional($product->category)->category_name)
                                            <span><i class="bi bi-folder2"></i> {{ $product->category->category_name }}</span>
                                        @endif
                                    </div>
                                    <div class="mb-2">
                                        <div class="text-uppercase small text-muted">Harga</div>
                                        <div class="h5 mb-0">
                                            {{ number_format((float)($product->display_sale_price ?? 0), 0, ',', '.') }}
                                        </div>
                                    </div>

                                    @if($product->conversions && $product->conversions->count())
                                        <div class="mt-2">
                                            <div class="text-uppercase small text-muted mb-1">Konversi</div>
                                            <div class="d-flex flex-wrap gap-1">
                                                @foreach($product->conversions as $uc)
                                                    <span class="unit-chip">
                                                        {{ $uc->unit->short_name ?? $uc->unit->name ?? 'Unit' }}
                                                        @if($uc->quantity) x{{ (int)$uc->quantity }} @endif
                                                        @if(!is_null($uc->price)) • {{ number_format((float)$uc->price, 0, ',', '.') }} @endif
                                                        @if($uc->barcode) • {{ $uc->barcode }} @endif
                                                    </span>
                                                @endforeach
                                            </div>
                                        </div>
                                    @endif
                                </div>
                            </div>
                        </div>
                    </div>
                @endforeach
            </div>

            <div class="d-flex justify-content-between align-items-center mt-3">
                <div class="text-muted small">
                    Menampilkan {{ $products->firstItem() }}–{{ $products->lastItem() }} dari {{ $products->total() }}
                </div>
                <div>
                    {{ $products->onEachSide(1)->links() }}
                </div>
            </div>
        @endif
    </div>
</div>

{{-- @deprecated - Replaced by Alpine.js implementation in product-search-alpine.blade.php --}}
{{-- This file is kept for reference but should not be used for new development --}}
<div class="position-relative">
    <div class="card mb-0 border-0 shadow-sm">
        <div class="card-body">
            <div class="form-group mb-0">
                <div class="input-group">
                    <div class="input-group-prepend">
                        <div class="input-group-text">
                            <i class="bi bi-search text-primary"></i>
                        </div>
                    </div>
                    <div class="flex-grow-1 position-relative"
                         x-data="productSearch($wire, @entangle('supplier_id').live)"
                         x-init="init()">

                        <!-- Product Search Input -->
                        <input
                            type="text"
                            class="form-control"
                            x-model="query"
                            @input.debounce.500ms="search()"
                            @focus="open = true"
                            @blur="setTimeout(() => open = false, 150)"
                            placeholder="Ketik nama atau kode produk...."
                            autocomplete="off"
                        >

                        <!-- Dropdown Results -->
                        <div class="dropdown-menu w-100 shadow show"
                             x-show="open && results.length > 0"
                             x-cloak
                             style="position: absolute; z-index: 1050; max-height: 250px; overflow-y: auto; top: 100%; left: 0; right: 0;">
                            <template x-for="product in results" :key="product.id">
                                <button
                                    type="button"
                                    @mousedown.prevent="selectProduct(product)"
                                    class="dropdown-item"
                                    x-text="product.display_name"
                                ></button>
                            </template>
                            <template x-if="results.length >= howMany">
                                <button
                                    type="button"
                                    @mousedown.prevent="loadMore()"
                                    class="dropdown-item text-center"
                                >
                                    Load More <i class="bi bi-arrow-down-circle"></i>
                                </button>
                            </template>
                        </div>

                        <!-- No Results -->
                        <div class="dropdown-menu w-100 show"
                             x-show="open && query.length >= 2 && results.length === 0 && !loading"
                             x-cloak
                             style="position: absolute; z-index: 1050; top: 100%; left: 0; right: 0;">
                            <div class="dropdown-item disabled">Produk tidak ditemukan....</div>
                        </div>

                        <!-- Loading -->
                        <div class="dropdown-menu w-100 show"
                             x-show="open && loading"
                             x-cloak
                             style="position: absolute; z-index: 1050; top: 100%; left: 0; right: 0;">
                            <div class="dropdown-item disabled">Mencari...</div>
                        </div>
                    </div>
                    <x-quick-add-button
                        entity="produk"
                        permission="products.create"
                        modal-event="openProductModal"
                        tooltip="Tambah produk baru"
                    />
                </div>
            </div>
        </div>
    </div>
</div>

<script>
function productSearch($wire, supplierId) {
    return {
        query: '',
        results: [],
        supplierId: supplierId,
        open: false,
        loading: false,
        howMany: 5,
        abortController: null,

        init() {
            // Listen for supplier changes
            $wire.on('supplierSelected', (supplierId) => {
                this.supplierId = supplierId;
                this.query = '';
                this.results = [];
                this.open = false;
            });
        },

        async search() {
            if (this.query.length < 2) {
                this.results = [];
                this.open = false;
                return;
            }

            this.loading = true;
            this.open = true;

            // Cancel previous request
            if (this.abortController) {
                this.abortController.abort();
            }

            this.abortController = new AbortController();

            try {
                const params = new URLSearchParams({
                    query: this.query,
                    supplier_id: this.supplierId || '',
                    limit: this.howMany
                });
                const response = await fetch(`/api/products/search?${params}`, {
                    signal: this.abortController.signal,
                    headers: {
                        'X-Requested-With': 'XMLHttpRequest',
                        'Accept': 'application/json'
                    }
                });

                if (!response.ok) {
                    throw new Error('Network response was not ok');
                }

                const data = await response.json();
                this.results = data;
            } catch (error) {
                if (error.name !== 'AbortError') {
                    console.error('Search error:', error);
                    this.results = [];
                }
            } finally {
                this.loading = false;
            }
        },

        selectProduct(product) {
            this.query = '';
            this.results = [];
            this.open = false;
            $wire.call('selectProduct', product);
        },

        loadMore() {
            this.howMany += 5;
            this.search();
        }
    }
}
</script>

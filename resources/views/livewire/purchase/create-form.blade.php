{{-- @deprecated - Replaced by Alpine.js implementation in create-alpine.blade.php --}}
{{-- This file is kept for reference but should not be used for new development --}}
<div class="card-body">
    <form wire:submit.prevent="submit">
        <input type="hidden" wire:model="idempotencyToken">
        <div class="form-row">
            <!-- Referensi -->
            <div class="col-lg-6 mb-3">
                <label for="reference">Referensi <span class="text-danger">*</span></label>
                <input type="text" class="form-control" id="reference" readonly wire:model="reference">
            </div>

            <!-- Supplier -->
            <div class="col-lg-6 mb-3">
                <label for="supplier_search">Pemasok <span class="text-danger">*</span></label>
                <div class="d-flex">
                    <div class="flex-grow-1 position-relative"
                         x-data="supplierSearch($wire, @entangle('supplier_id').live, @js($supplier_id ? \Modules\People\Entities\Supplier::find($supplier_id)?->supplier_name : null))"
                         x-init="init()">

                        <!-- Selected Supplier Display -->
                        <template x-if="selectedId">
                            <div class="form-control d-flex justify-content-between align-items-center">
                                <span x-text="selectedName"></span>
                                <button type="button" @click="clearSelection()" class="btn btn-sm btn-light">
                                    <i class="bi bi-x"></i>
                                </button>
                            </div>
                        </template>

                        <!-- Supplier Search Input -->
                        <template x-if="!selectedId">
                            <div>
                                <input
                                    type="text"
                                    class="form-control"
                                    id="supplier_search"
                                    x-model="query"
                                    @input.debounce.300ms="search()"
                                    @focus="open = true"
                                    @blur="setTimeout(() => open = false, 150)"
                                    placeholder="Cari pemasok..."
                                    autocomplete="off"
                                >

                                <!-- Dropdown Results -->
                                <div class="dropdown-menu w-100 shadow show"
                                     x-show="open && results.length > 0"
                                     x-cloak
                                     style="position: absolute; z-index: 1050; max-height: 250px; overflow-y: auto; top: 100%; left: 0; right: 0;">
                                    <template x-for="supplier in results" :key="supplier.id">
                                        <button
                                            type="button"
                                            @mousedown.prevent="selectSupplier(supplier)"
                                            class="dropdown-item"
                                            x-text="supplier.display_name"
                                        ></button>
                                    </template>
                                </div>

                                <!-- No Results -->
                                <div class="dropdown-menu w-100 show"
                                     x-show="open && query.length >= 2 && results.length === 0 && !loading"
                                     x-cloak
                                     style="position: absolute; z-index: 1050; top: 100%; left: 0; right: 0;">
                                    <div class="dropdown-item disabled">Tidak ada hasil</div>
                                </div>

                                <!-- Loading -->
                                <div class="dropdown-menu w-100 show"
                                     x-show="open && loading"
                                     x-cloak
                                     style="position: absolute; z-index: 1050; top: 100%; left: 0; right: 0;">
                                    <div class="dropdown-item disabled">Mencari...</div>
                                </div>
                            </div>
                        </template>
                    </div>
                    <x-quick-add-button
                        entity="pemasok"
                        permission="suppliers.create"
                        modal-event="openSupplierModal"
                        tooltip="Tambah pemasok baru"
                    />
                </div>
                @error('supplier_id')
                <div class="text-danger">{{ $message }}</div> @enderror
            </div>

            <div class="col-lg-6 mb-3">
                <label for="supplier_purchase_number">Nomor Pembelian Supplier</label>
                <input type="text" class="form-control" id="supplier_purchase_number" wire:model="supplier_purchase_number" placeholder="Opsional">
                @error('supplier_purchase_number')
                <div class="text-danger">{{ $message }}</div> @enderror
            </div>

            <!-- Tanggal -->
            <div class="col-lg-6 mb-3">
                <label for="date">Tanggal <span class="text-danger">*</span></label>
                <input type="date" class="form-control" id="date" wire:model="date">
                @error('date')
                <div class="text-danger">{{ $message }}</div> @enderror
            </div>

            <!-- Jatuh Tempo -->
            <div class="col-lg-6 mb-3">
                <label for="due_date">Tanggal Jatuh Tempo <span class="text-danger">*</span></label>
                <input type="date" class="form-control" id="due_date" wire:model="due_date">
                @error('due_date')
                <div class="text-danger">{{ $message }}</div> @enderror
            </div>

            <!-- Payment Term -->
            <div class="col-lg-6 mb-3">
                <label for="payment_term_search">Term Pembayaran <span class="text-danger">*</span></label>
                <div class="d-flex">
                    <div class="flex-grow-1 position-relative"
                         x-data="paymentTermSearch($wire, @entangle('payment_term').live, @js($payment_term ? \Modules\Purchase\Entities\PaymentTerm::find($payment_term)?->name : null))"
                         x-init="init()"
                         x-effect="updateSelectedName()">
                        
                        <!-- Selected Payment Term Display -->
                        <template x-if="selectedId">
                            <div class="form-control d-flex justify-content-between align-items-center">
                                <span x-text="selectedName" @click="clearSelection()"></span>
                                <button type="button" @click="clearSelection()" class="btn btn-sm btn-light">
                                    <i class="bi bi-x"></i>
                                </button>
                            </div>
                        </template>

                        <!-- Payment Term Search Input -->
                        <template x-if="!selectedId">
                            <div>
                                <input
                                    type="text"
                                    class="form-control"
                                    id="payment_term_search"
                                    x-model="query"
                                    @input.debounce.300ms="search()"
                                    @focus="open = true"
                                    @blur="setTimeout(() => open = false, 150)"
                                    placeholder="Cari term pembayaran..."
                                    autocomplete="off"
                                >

                                <!-- Dropdown Results -->
                                <div class="dropdown-menu w-100 shadow show"
                                     x-show="open && results.length > 0"
                                     x-cloak
                                     style="position: absolute; z-index: 1050; max-height: 250px; overflow-y: auto; top: 100%; left: 0; right: 0;">
                                    <template x-for="term in results" :key="term.id">
                                        <button
                                            type="button"
                                            @mousedown.prevent="selectTerm(term)"
                                            class="dropdown-item"
                                            x-text="term.display_name"
                                        ></button>
                                    </template>
                                </div>

                                <!-- No Results -->
                                <div class="dropdown-menu w-100 show"
                                     x-show="open && query.length >= 2 && results.length === 0 && !loading"
                                     x-cloak
                                     style="position: absolute; z-index: 1050; top: 100%; left: 0; right: 0;">
                                    <div class="dropdown-item disabled">Tidak ada hasil</div>
                                </div>

                                <!-- Loading -->
                                <div class="dropdown-menu w-100 show"
                                     x-show="open && loading"
                                     x-cloak
                                     style="position: absolute; z-index: 1050; top: 100%; left: 0; right: 0;">
                                    <div class="dropdown-item disabled">Mencari...</div>
                                </div>
                            </div>
                        </template>
                    </div>
                    <x-quick-add-button
                        entity="term pembayaran"
                        permission="purchases.create"
                        modal-event="openPaymentTermModal"
                        tooltip="Tambah term pembayaran baru"
                    />
                </div>
                @error('payment_term')
                <div class="text-danger">{{ $message }}</div> @enderror
            </div>

            <div class="col-lg-6 mb-3">
                <label for="tags">Tag Pembelian</label>
                <livewire:utils.tag-selector :initial-tags="$tags ?? []" />
            </div>
        </div>

        <!-- Product Cart -->
        <div class="my-3">
            <livewire:purchase.product-cart :cartInstance="'purchase'" />
        </div>

        <!-- Catatan -->
        <div class="form-group">
            <label for="note">Catatan</label>
            <textarea class="form-control" rows="4" wire:model="note"></textarea>
            @error('note')
            <div class="text-danger">{{ $message }}</div> @enderror
        </div>

        <!-- Submit -->
        <div class="mt-3">
            <button type="button" class="btn btn-primary" id="submitWithConfirmation"
                data-processing-text="Memproses…"
                data-default-text="Buat Pembelian"
            >
                <span class="spinner-border spinner-border-sm mr-2 d-none button-spinner" role="status" aria-hidden="true"></span>
                <span class="button-text">Buat Pembelian</span>
                <i class="bi bi-check ml-1"></i>
            </button>
            <a href="{{ route('purchases.index') }}" class="btn btn-secondary">Kembali</a>
        </div>
    </form>

    <!-- Modals -->
    <livewire:modules.purchase.modals.payment-term-quick-add-modal wire:key="purchase-payment-term-modal" />
    <livewire:modules.people.modals.supplier-quick-add-modal wire:key="purchase-supplier-modal" />
    <livewire:modules.product.modals.product-quick-add-modal wire:key="purchase-product-modal" />
    <livewire:modules.setting.modals.tax-quick-add-modal wire:key="purchase-tax-modal" />
</div>

<script>
function supplierSearch($wire, selectedId, initialName) {
    return {
        query: '',
        results: [],
        selectedId: selectedId,
        selectedName: initialName || '',
        open: false,
        loading: false,
        abortController: null,

        init() {
            // Listen for supplier creation events
            Livewire.on('supplierCreated', (data) => {
                this.selectedId = data.id;
                this.selectedName = data.supplier_name;
                this.query = '';
                this.results = [];
                this.open = false;

                // Auto-fill payment term
                $wire.call('selectSupplier', data.id);
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
                const response = await fetch(`/api/suppliers/search?query=${encodeURIComponent(this.query)}&limit=10`, {
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

        selectSupplier(supplier) {
            this.selectedId = supplier.id;
            this.selectedName = supplier.display_name;
            this.query = '';
            this.results = [];
            this.open = false;

            // Set Livewire property
            $wire.set('supplier_id', supplier.id);
        },

        clearSelection() {
            this.selectedId = null;
            this.selectedName = '';
            this.query = '';
            this.results = [];
            this.open = false;

            // Set Livewire property
            $wire.set('supplier_id', null);
        }
    }
}

function paymentTermSearch($wire, selectedId, initialName) {
    return {
        query: '',
        results: @js($paymentTerms->map(fn($term) => ['id' => $term->id, 'display_name' => $term->name])->toArray()),
        allTerms: @js($paymentTerms->map(fn($term) => ['id' => $term->id, 'display_name' => $term->name])->toArray()),
        selectedId: selectedId,
        selectedName: initialName || '',
        open: false,
        loading: false,

        init() {
            this.updateSelectedName();
            // Listen for payment term creation events
            Livewire.on('paymentTermCreated', (data) => {
                this.selectedId = data.id;
                this.selectedName = data.name;
                this.query = '';
                this.results = [];
                this.open = false;

                // Update the results list
                this.results.push({id: data.id, display_name: data.name});
                this.allTerms.push({id: data.id, display_name: data.name});
            });
        },

        updateSelectedName() {
            if (this.selectedId) {
                const term = this.allTerms.find(t => t.id == this.selectedId);
                if (term) {
                    this.selectedName = term.display_name;
                }
            } else {
                this.selectedName = '';
            }
        },

        search() {
            if (this.query.length < 1) {
                this.results = this.allTerms;
                this.open = false;
                return;
            }

            this.open = true;
            this.results = this.allTerms.filter(term => 
                term.display_name.toLowerCase().includes(this.query.toLowerCase())
            );
        },

        selectTerm(term) {
            this.selectedId = term.id;
            this.selectedName = term.display_name;
            this.query = '';
            this.results = [];
            this.open = false;

            // Set Livewire property
            $wire.set('payment_term', term.id);
        },

        clearSelection() {
            this.selectedId = null;
            this.selectedName = '';
            this.query = '';
            this.results = [];
            this.open = false;

            // Set Livewire property
            $wire.set('payment_term', null);
        }
    }
}
</script>

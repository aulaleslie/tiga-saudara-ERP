@extends('layouts.app')

@section('title', 'Buat Pembelian Baru')

@section('breadcrumb')
    <ol class="breadcrumb border-0 m-0">
        <li class="breadcrumb-item"><a href="{{ route('home') }}">Beranda</a></li>
        <li class="breadcrumb-item"><a href="{{ route('purchases.index') }}">Pembelian</a></li>
        <li class="breadcrumb-item active">Tambah Baru</li>
    </ol>
@endsection

@section('content')
    <div class="container-fluid mb-4">
        <!-- Product Search Component -->
        <div class="row">
            <div class="col-12">
                @include('purchase::includes.product-search-alpine')
            </div>
        </div>

        <!-- Purchase Form -->
        <div class="row mt-4">
            <div class="col-md-12">
                <div class="card">
                    <div class="card-body">
                        <form
                            x-data="purchaseForm()"
                            @submit.prevent="submitForm()"
                            @supplier-selected.window="handleSupplierSelected($event.detail)"
                            @payment-term-selected.window="handlePaymentTermSelected($event.detail)"
                            method="POST"
                            action="{{ route('purchases.store') }}"
                        >
                            @csrf
                            <input type="hidden" name="idempotency_token" x-model="idempotencyToken">

                            <div class="form-row">
                                <!-- Supplier -->
                                <div class="col-lg-6 mb-3">
                                    <label for="supplier_search">Pemasok <span class="text-danger">*</span></label>
                                    <div class="d-flex">
                                        <div class="flex-grow-1 position-relative"
                                             x-data="searchableDropdown()"
                                             x-init="
                                                 config = {
                                                     apiUrl: '/api/suppliers/search',
                                                     entityType: 'supplier',
                                                     placeholder: 'Cari pemasok...',
                                                     displayField: 'display_name',
                                                     valueField: 'id',
                                                     initialSelectedId: form.supplier_id,
                                                     limit: 10,
                                                     minQueryLength: 2,
                                                     additionalParams: {}
                                                 };
                                                 init();
                                             "
                                        >
                                            <!-- Selected Supplier Display -->
                                            <template x-if="selectedId">
                                                <div class="form-control" @click="showInput()">
                                                    <span x-text="selectedName"></span>
                                                </div>
                                            </template>

                                            <!-- Supplier Search Input -->
                                            <template x-if="!selectedId">
                                                        <div>
                                                            <input
                                                                type="text"
                                                                class="form-control"
                                                                x-model="inputValue"
                                                                @input.debounce.300ms="updatedQuery()"
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
                                                                @mousedown.prevent="selectItem(supplier)"
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
                                        <button type="button" class="btn btn-outline-primary btn-sm ms-1"
                                                @click="$dispatch('open-supplier-modal')"
                                                data-bs-toggle="tooltip" title="Tambah pemasok baru">
                                            <i class="bi bi-plus-circle"></i>
                                        </button>
                                    </div>
                                    <input type="hidden" name="supplier_id" x-model="form.supplier_id">
                                    <div x-show="errors.supplier_id" class="text-danger small mt-1" x-text="errors.supplier_id"></div>
                                </div>

                                <div class="col-lg-6 mb-3">
                                    <label for="supplier_purchase_number">Nomor Pembelian Supplier</label>
                                    <input type="text" class="form-control" id="supplier_purchase_number"
                                           x-model="form.supplier_purchase_number" placeholder="Opsional">
                                </div>

                                <!-- Date -->
                                <div class="col-lg-6 mb-3">
                                    <label for="date">Tanggal <span class="text-danger">*</span></label>
                                    <input type="date" class="form-control" id="date" x-model="form.date" @change="updateDueDate()">
                                </div>

                                <!-- Due Date -->
                                <div class="col-lg-6 mb-3">
                                    <label for="due_date">Tanggal Jatuh Tempo <span class="text-danger">*</span></label>
                                    <input type="date" class="form-control" id="due_date" x-model="form.due_date">
                                </div>

                                <!-- Payment Term -->
                                <div class="col-lg-6 mb-3">
                                    <label for="payment_term_search">Term Pembayaran <span class="text-danger">*</span></label>
                                    <div class="d-flex">
                                        <div class="flex-grow-1 position-relative"
                                             x-data="searchableDropdown()"
                                             x-init="
                                                 config = {
                                                     apiUrl: null, // Local filtering
                                                     entityType: 'paymentTerm',
                                                     placeholder: 'Cari term pembayaran...',
                                                     displayField: 'name',
                                                     valueField: 'id',
                                                     initialSelectedId: form.payment_term,
                                                     minQueryLength: 1,
                                                     staticOptions: @js($paymentTerms ?? []),
                                                     additionalParams: {}
                                                 };
                                                 results = @js($paymentTerms ?? []);
                                                 allTerms = @js($paymentTerms ?? []);
                                                 init();
                                             "
                                             @payment-term-update.window="handlePaymentTermUpdate($event.detail)"
                                             @payment-term-clear.window="handlePaymentTermClear()"
                                             @click.outside="open = false"
                                        >
                                            <div class="form-control d-flex justify-content-between align-items-center"
                                                 style="cursor: pointer;"
                                                 @click="open = !open; if(open){ search(); }">
                                                <span x-text="selectedName || 'Pilih term pembayaran...'"></span>
                                                <i class="bi" :class="open ? 'bi-chevron-up' : 'bi-chevron-down'"></i>
                                            </div>

                                            <div class="dropdown-menu w-100 shadow show p-2"
                                                 x-show="open"
                                                 x-cloak
                                                 style="position: absolute; z-index: 1050; max-height: 300px; overflow-y: auto; top: 100%; left: 0; right: 0;">
                                                <input
                                                    type="text"
                                                    class="form-control form-control-sm mb-2"
                                                    x-model="inputValue"
                                                    @input.debounce.300ms="search()"
                                                    placeholder="Cari term pembayaran..."
                                                    autocomplete="off"
                                                >
                                                <template x-if="results.length > 0">
                                                    <div>
                                                        <template x-for="term in results" :key="term.id">
                                                            <button
                                                                type="button"
                                                                @mousedown.prevent="selectItem(term); open = false;"
                                                                class="dropdown-item"
                                                                x-text="term.name"
                                                            ></button>
                                                        </template>
                                                    </div>
                                                </template>
                                                <template x-if="results.length === 0">
                                                    <div class="dropdown-item disabled">Tidak ada hasil</div>
                                                </template>
                                            </div>
                                        </div>
                                        <button type="button" class="btn btn-outline-primary btn-sm ms-1"
                                                @click="$dispatch('open-payment-term-modal')"
                                                data-bs-toggle="tooltip" title="Tambah term pembayaran baru">
                                            <i class="bi bi-plus-circle"></i>
                                        </button>
                                    </div>
                                    <input type="hidden" name="payment_term" x-model="form.payment_term">
                                    <div x-show="errors.payment_term" class="text-danger small mt-1" x-text="errors.payment_term"></div>
                                </div>

                                <div class="col-lg-6 mb-3">
                                    <label for="tags">Tag Pembelian</label>
                                    <input type="text" class="form-control" id="tags" x-model="form.tags" placeholder="Tag (opsional)">
                                </div>
                            </div>

                            <!-- Product Cart -->
                            <div class="my-3">
                                @include('purchase::includes.product-cart-alpine')
                            </div>

                            <!-- Notes -->
                            <div class="form-group">
                                <label for="note">Catatan</label>
                                <textarea class="form-control" rows="4" x-model="form.note"></textarea>
                            </div>

                            <!-- Submit -->
                            <div class="mt-3">
                                <button type="submit" class="btn btn-primary" :disabled="submitting">
                                    <span x-show="submitting" class="spinner-border spinner-border-sm me-2" role="status"></span>
                                    <span x-text="submitting ? 'Memproses...' : 'Buat Pembelian'"></span>
                                    <i class="bi bi-check ms-1"></i>
                                </button>
                                <a href="{{ route('purchases.index') }}" class="btn btn-secondary">Kembali</a>
                            </div>
                        </form>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Modals -->
    @include('components.alpine.supplier-quick-add-modal', ['paymentTerms' => $paymentTerms ?? []])
    @include('components.alpine.payment-term-quick-add-modal')
    @include('components.alpine.tax-quick-add-modal')
    @include('components.alpine.product-quick-add-modal', [
        'categories' => $categories ?? [],
        'brands' => $brands ?? [],
        'units' => $units ?? []
    ])

    @include('components.confirmation-modal')
@endsection

@push('page_scripts')
<script>
function purchaseForm() {
    return {
        idempotencyToken: '{{ $idempotencyToken ?? Str::uuid() }}',
        submitting: false,
        paymentTerms: @js($paymentTerms ?? []),
        taxes: @js($taxes ?? []),
        form: {
            reference: 'PR',
            supplier_id: null,
            supplier_purchase_number: '',
            date: '{{ now()->format('Y-m-d') }}',
            due_date: '{{ now()->format('Y-m-d') }}',
            payment_term: null,
            tags: '',
            note: '',
            is_tax_included: true,
            global_discount: 0,
            global_discount_type: 'percentage',
            shipping: 0
        },
        cart: [],
        cartTotals: {
            subtotalBeforeTax: 0,
            taxTotal: 0,
            subtotal: 0,
            globalDiscountAmount: 0,
            shipping: 0,
            grandTotal: 0
        },
        errors: {},

        init() {
            // Generate reference if needed
            this.updateReference();

            // Keep due date in sync whenever payment term changes
            this.$watch('form.payment_term', () => {
                this.updateDueDate();
            });

            // Sync cart updates from product cart component
            window.addEventListener('cart-updated', (event) => {
                const payload = event.detail || {};
                this.cart = payload.cart || [];
                this.cartTotals = payload.totals || this.cartTotals;
                this.form.shipping = payload.shipping ?? this.form.shipping;
                this.form.global_discount = payload.globalDiscount ?? this.form.global_discount;
                this.form.global_discount_type = payload.globalDiscountType ?? this.form.global_discount_type;
                this.form.is_tax_included = payload.isTaxIncluded ?? this.form.is_tax_included;
            });
        },

        updateReference() {
            // This would normally be handled by the model, but for now we'll keep it simple
            this.form.reference = 'PR-' + Date.now();
        },

        handleSupplierSelected(data) {
            if (!data) return;

            this.form.supplier_id = data.id || null;
            const paymentTermId = data.payment_term_id ?? data.item?.payment_term_id ?? null;

            if (paymentTermId) {
                const term = this.paymentTerms.find(t => t.id == paymentTermId);
                this.form.payment_term = paymentTermId;
                this.$nextTick(() => {
                    window.dispatchEvent(new CustomEvent('payment-term-update', {
                        detail: { id: paymentTermId, name: term?.name }
                    }));
                    this.updateDueDate();
                });
            } else {
                this.form.payment_term = null;
                this.$nextTick(() => {
                    window.dispatchEvent(new CustomEvent('payment-term-clear'));
                });
                this.updateDueDate();
            }
            this.updateDueDate();
        },

        handlePaymentTermSelected(data) {
            if (!data) return;

            this.form.payment_term = data.id;
            this.updateDueDate();
        },

        updateDueDate() {
            if (this.form.payment_term && this.form.date) {
                const term = this.paymentTerms.find(t => t.id == this.form.payment_term);
                if (term) {
                    const date = new Date(this.form.date);
                    date.setDate(date.getDate() + parseInt(term.longevity));
                    this.form.due_date = date.toISOString().split('T')[0];
                    return;
                }
            }

            // Fallback: align due date with selected date when no term
            if (this.form.date) {
                this.form.due_date = this.form.date;
            }
        },

        async submitForm() {
            if (!this.cart || this.cart.length === 0) {
                alert('Produk harus dipilih');
                return;
            }

            this.submitting = true;

            try {
                // Prepare form data
                const formData = new FormData();
                Object.keys(this.form).forEach(key => {
                    formData.append(key, this.form[key]);
                });

                // Append totals and financials consistent with legacy handling
                formData.append('shipping_amount', this.cartTotals.shipping ?? 0);
                formData.append('is_tax_included', this.form.is_tax_included ? 1 : 0);
                formData.append('total_amount', this.cartTotals.grandTotal ?? 0);

                const discountType = this.form.global_discount_type;
                const discountValue = this.form.global_discount ?? 0;
                if (discountType === 'percentage') {
                    formData.append('discount_percentage', discountValue);
                    formData.append('discount_amount', 0);
                } else {
                    formData.append('discount_percentage', 0);
                    formData.append('discount_amount', discountValue);
                }

                // Add cart items
                this.cart.forEach((item, index) => {
                    formData.append(`cart[${index}][product_id]`, item.product_id);
                    formData.append(`cart[${index}][quantity]`, item.quantity);
                    formData.append(`cart[${index}][unit_price]`, item.unit_price);
                    formData.append(`cart[${index}][discount_type]`, item.discount_type);
                    formData.append(`cart[${index}][discount]`, item.discount);
                    formData.append(`cart[${index}][tax_id]`, item.tax_id || '');
                });

                const response = await fetch('{{ route('purchases.store') }}', {
                    method: 'POST',
                    body: formData,
                    headers: {
                        'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').getAttribute('content')
                    }
                });

                if (response.redirected) {
                    window.location.href = response.url;
                } else {
                    const data = await response.json();
                    if (!response.ok) {
                        if (data.errors) {
                            this.errors = data.errors;
                        }
                        throw new Error(data.message || 'Failed to create purchase');
                    }
                }
            } catch (error) {
                console.error('Error submitting form:', error);
                alert('Gagal menyimpan pembelian. Silakan coba lagi.');
            } finally {
                this.submitting = false;
            }
        }
    };
}
</script>
@endpush

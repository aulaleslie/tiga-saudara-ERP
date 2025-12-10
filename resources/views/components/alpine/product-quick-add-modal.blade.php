@props([
    'categories' => [],
    'brands' => [],
    'units' => []
])

<div
    x-data="productQuickAddModal()"
    x-show="showModal"
    x-cloak
    class="modal fade"
    tabindex="-1"
    style="display: none;"
    @open-product-modal.window="openModal()"
>
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Tambah Produk Baru</h5>
                <button type="button" class="btn-close" @click="closeModal()" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <form @submit.prevent="save()">
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label for="product_name" class="form-label">Nama Produk <span class="text-danger">*</span></label>
                            <input
                                type="text"
                                class="form-control"
                                id="product_name"
                                x-model="form.product_name"
                                required
                            >
                            <div x-show="errors.product_name" class="text-danger small mt-1" x-text="errors.product_name"></div>
                        </div>

                        <div class="col-md-6 mb-3">
                            <label for="product_code" class="form-label">Kode Produk</label>
                            <input
                                type="text"
                                class="form-control"
                                id="product_code"
                                x-model="form.product_code"
                            >
                            <div x-show="errors.product_code" class="text-danger small mt-1" x-text="errors.product_code"></div>
                        </div>
                    </div>

                    <div class="row">
                        <div class="col-md-4 mb-3">
                            <label for="category_id" class="form-label">Kategori</label>
                            <select
                                class="form-control"
                                id="category_id"
                                x-model="form.category_id"
                            >
                                <option value="">Pilih Kategori</option>
                                <template x-for="category in categories" :key="category.id">
                                    <option :value="category.id" x-text="category.category_name"></option>
                                </template>
                            </select>
                        </div>

                        <div class="col-md-4 mb-3">
                            <label for="brand_id" class="form-label">Merek</label>
                            <select
                                class="form-control"
                                id="brand_id"
                                x-model="form.brand_id"
                            >
                                <option value="">Pilih Merek</option>
                                <template x-for="brand in brands" :key="brand.id">
                                    <option :value="brand.id" x-text="brand.brand_name"></option>
                                </template>
                            </select>
                        </div>

                        <div class="col-md-4 mb-3">
                            <label for="unit_id" class="form-label">Unit <span class="text-danger">*</span></label>
                            <select
                                class="form-control"
                                id="unit_id"
                                x-model="form.unit_id"
                                required
                            >
                                <option value="">Pilih Unit</option>
                                <template x-for="unit in units" :key="unit.id">
                                    <option :value="unit.id" x-text="unit.name"></option>
                                </template>
                            </select>
                            <div x-show="errors.unit_id" class="text-danger small mt-1" x-text="errors.unit_id"></div>
                        </div>
                    </div>

                    <div class="mb-3">
                        <label for="description" class="form-label">Deskripsi</label>
                        <textarea
                            class="form-control"
                            id="description"
                            rows="3"
                            x-model="form.description"
                        ></textarea>
                    </div>

                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <div class="form-check">
                                <input
                                    class="form-check-input"
                                    type="checkbox"
                                    id="is_sold"
                                    x-model="form.is_sold"
                                >
                                <label class="form-check-label" for="is_sold">
                                    Dapat dijual
                                </label>
                            </div>
                        </div>

                        <div class="col-md-6 mb-3">
                            <div class="form-check">
                                <input
                                    class="form-check-input"
                                    type="checkbox"
                                    id="is_purchased"
                                    x-model="form.is_purchased"
                                    checked
                                >
                                <label class="form-check-label" for="is_purchased">
                                    Dapat dibeli
                                </label>
                            </div>
                        </div>
                    </div>
                </form>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" @click="closeModal()">Batal</button>
                <button
                    type="button"
                    class="btn btn-primary"
                    @click="save()"
                    :disabled="saving"
                >
                    <span x-show="saving" class="spinner-border spinner-border-sm me-2" role="status"></span>
                    <span x-text="saving ? 'Menyimpan...' : 'Simpan'"></span>
                </button>
            </div>
        </div>
    </div>
</div>

<script>
function productQuickAddModal() {
    return {
        showModal: false,
        saving: false,
        categories: @js($categories ?? []),
        brands: @js($brands ?? []),
        units: @js($units ?? []),
        form: {
            product_name: '',
            product_code: '',
            category_id: '',
            brand_id: '',
            unit_id: '',
            description: '',
            is_sold: false,
            is_purchased: true
        },
        errors: {},

        openModal() {
            this.resetForm();
            this.showModal = true;
        },

        closeModal() {
            this.showModal = false;
            this.resetForm();
        },

        resetForm() {
            this.form = {
                product_name: '',
                product_code: '',
                category_id: '',
                brand_id: '',
                unit_id: '',
                description: '',
                is_sold: false,
                is_purchased: true
            };
            this.errors = {};
            this.saving = false;
        },

        async save() {
            this.saving = true;
            this.errors = {};

            try {
                const response = await fetch('/api/products', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').getAttribute('content'),
                        'Accept': 'application/json'
                    },
                    body: JSON.stringify(this.form)
                });

                const data = await response.json();

                if (!response.ok) {
                    if (response.status === 422 && data.errors) {
                        this.errors = data.errors;
                    } else {
                        throw new Error(data.message || 'Failed to create product');
                    }
                    return;
                }

                // Dispatch success event
                window.dispatchEvent(new CustomEvent('productCreated', { detail: data }));

                this.closeModal();

                // Show success message
                if (window.toast) {
                    window.toast('Produk berhasil ditambahkan!', 'success');
                }
            } catch (error) {
                console.error('Error creating product:', error);
                if (window.toast) {
                    window.toast('Gagal menambahkan produk. Silakan coba lagi.', 'error');
                }
            } finally {
                this.saving = false;
            }
        }
    };
}
</script>
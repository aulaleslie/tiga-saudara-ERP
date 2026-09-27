<div
    x-data="categoryQuickAddModal()"
    x-show="showModal"
    x-cloak
    class="fixed inset-0 z-50 overflow-y-auto"
    @open-category-modal.window="openModal()"
    @keydown.escape.window="closeModal()"
>
    <!-- Backdrop -->
    <div class="fixed inset-0" style="background: rgba(0,0,0,0.15);" @click="closeModal()"></div>

    <!-- Modal Content -->
    <div class="relative min-h-screen flex items-center justify-center p-4">
        <div class="relative bg-white rounded-lg shadow-xl max-w-lg w-full">
            <div class="modal-header bg-light px-4 py-3 border-bottom">
                <h5 class="modal-title mb-0">Tambah Kategori Baru</h5>
                <button type="button" class="btn-close" @click="closeModal()" aria-label="Close"></button>
            </div>
            <div class="modal-body p-4">
                <form @submit.prevent="save()">
                    <div class="mb-3">
                        <label for="category_code" class="form-label">Kode Kategori</label>
                        <input
                            type="text"
                            class="form-control"
                            id="category_code"
                            x-model="form.category_code"
                            placeholder="Kosongkan untuk auto-generate"
                        >
                        <div x-show="errors.category_code" class="text-danger small mt-1" x-text="errors.category_code"></div>
                    </div>

                    <div class="mb-3">
                        <label for="category_name" class="form-label">Nama Kategori <span class="text-danger">*</span></label>
                        <input
                            type="text"
                            class="form-control"
                            id="category_name"
                            x-model="form.category_name"
                            x-ref="categoryNameInput"
                            required
                        >
                        <div x-show="errors.category_name" class="text-danger small mt-1" x-text="errors.category_name"></div>
                    </div>


                </form>
            </div>
            <div class="modal-footer px-4 py-3 border-top">
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
function categoryQuickAddModal() {
    return {
        showModal: false,
        saving: false,
        form: {
            category_code: '',
            category_name: '',
            parent_id: '',
            parent_name: '',
            add_as_subcategory: false,
        },
        errors: {},

        openModal() {
            this.resetForm();
            this.showModal = true;
            this.$nextTick(() => {
                this.$refs.categoryNameInput.focus();
            });
        },

        closeModal() {
            this.showModal = false;
            this.resetForm();
        },

        resetForm() {
            this.form = {
                category_code: '',
                category_name: '',
                parent_id: '',
                parent_name: '',
                add_as_subcategory: false,
            };
            this.errors = {};
            this.saving = false;
        },

        async save() {
            this.saving = true;
            this.errors = {};

            try {
                const response = await fetch('/api/categories', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').getAttribute('content'),
                        'Accept': 'application/json'
                    },
                    body: JSON.stringify({
                        category_code: this.form.category_code || null,
                        category_name: this.form.category_name,
                        parent_id: this.form.add_as_subcategory ? (this.form.parent_id || null) : null
                    })
                });

                const data = await response.json();

                if (!response.ok) {
                    if (response.status === 422 && data.errors) {
                        this.errors = data.errors;
                    } else {
                        throw new Error(data.message || 'Failed to create category');
                    }
                    return;
                }

                const parentName = this.form.parent_name;
                const computedDisplay = data.display_name
                    || (data.parent_id ? `${parentName || 'Kategori Induk'} | ${data.category_name}` : data.category_name);

                const normalized = {
                    ...data,
                    name: data.name || computedDisplay,
                    display_name: computedDisplay
                };

                // Dispatch success event
                window.dispatchEvent(new CustomEvent('categoryCreated', { detail: normalized }));

                this.closeModal();

                Swal.fire({
                    icon: 'success',
                    title: 'Berhasil!',
                    text: 'Kategori berhasil ditambahkan!',
                    timer: 2000,
                    showConfirmButton: false
                });
            } catch (error) {
                console.error('Error creating category:', error);
                Swal.fire({
                    icon: 'error',
                    title: 'Gagal!',
                    text: 'Gagal menambahkan kategori. Silakan coba lagi.',
                    confirmButtonText: 'OK'
                });
            } finally {
                this.saving = false;
            }
        }
    };
}
</script>

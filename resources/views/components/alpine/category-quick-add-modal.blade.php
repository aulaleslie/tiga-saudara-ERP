<div
    x-data="categoryQuickAddModal()"
    x-show="showModal"
    x-cloak
    class="modal fade"
    tabindex="-1"
    style="display: none;"
    @open-category-modal.window="openModal()"
>
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Tambah Kategori Baru</h5>
                <button type="button" class="btn-close" @click="closeModal()" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <form @submit.prevent="save()">
                    <div class="mb-3">
                        <label for="category_name" class="form-label">Nama Kategori <span class="text-danger">*</span></label>
                        <input
                            type="text"
                            class="form-control"
                            id="category_name"
                            x-model="form.category_name"
                            required
                        >
                        <div x-show="errors.category_name" class="text-danger small mt-1" x-text="errors.category_name"></div>
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
function categoryQuickAddModal() {
    return {
        showModal: false,
        saving: false,
        form: {
            category_name: ''
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
                category_name: ''
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
                    body: JSON.stringify(this.form)
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

                // Dispatch success event
                window.dispatchEvent(new CustomEvent('categoryCreated', { detail: data }));

                this.closeModal();

                // Show success message
                if (window.toast) {
                    window.toast('Kategori berhasil ditambahkan!', 'success');
                }
            } catch (error) {
                console.error('Error creating category:', error);
                if (window.toast) {
                    window.toast('Gagal menambahkan kategori. Silakan coba lagi.', 'error');
                }
            } finally {
                this.saving = false;
            }
        }
    };
}
</script>
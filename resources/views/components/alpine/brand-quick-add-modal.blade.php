<div
    x-data="brandQuickAddModal()"
    x-show="showModal"
    x-cloak
    class="modal fade"
    tabindex="-1"
    style="display: none;"
    @open-brand-modal.window="openModal()"
>
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Tambah Merek Baru</h5>
                <button type="button" class="btn-close" @click="closeModal()" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <form @submit.prevent="save()">
                    <div class="mb-3">
                        <label for="name" class="form-label">Nama Merek <span class="text-danger">*</span></label>
                        <input
                            type="text"
                            class="form-control"
                            id="name"
                            x-model="form.name"
                            required
                        >
                        <div x-show="errors.name" class="text-danger small mt-1" x-text="errors.name"></div>
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
function brandQuickAddModal() {
    return {
        showModal: false,
        saving: false,
        form: {
            name: ''
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
                name: ''
            };
            this.errors = {};
            this.saving = false;
        },

        async save() {
            this.saving = true;
            this.errors = {};

            try {
                const response = await fetch('/api/brands', {
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
                        throw new Error(data.message || 'Failed to create brand');
                    }
                    return;
                }

                // Dispatch success event
                window.dispatchEvent(new CustomEvent('brandCreated', { detail: data }));

                this.closeModal();

                // Show success message
                if (window.toast) {
                    window.toast('Merek berhasil ditambahkan!', 'success');
                }
            } catch (error) {
                console.error('Error creating brand:', error);
                if (window.toast) {
                    window.toast('Gagal menambahkan merek. Silakan coba lagi.', 'error');
                }
            } finally {
                this.saving = false;
            }
        }
    };
}
</script>
<div
    x-data="unitQuickAddModal()"
    x-show="showModal"
    x-cloak
    x-transition:enter="transition ease-out duration-200"
    x-transition:enter-start="opacity-0"
    x-transition:enter-end="opacity-100"
    x-transition:leave="transition ease-in duration-150"
    x-transition:leave-start="opacity-100"
    x-transition:leave-end="opacity-0"
    class="fixed inset-0 z-50 overflow-y-auto"
    @open-unit-modal.window="openModal()"
    @keydown.escape.window="closeModal()"
>
    <!-- Backdrop -->
    <div class="fixed inset-0 bg-black bg-opacity-50" @click="closeModal()"></div>

    <!-- Modal Content -->
    <div class="relative min-h-screen flex items-center justify-center p-4">
        <div class="relative bg-white rounded-lg shadow-xl max-w-lg w-full">
            <div class="modal-header bg-light px-4 py-3 border-bottom">
                <h5 class="modal-title mb-0">Tambah Unit Baru</h5>
                <button type="button" class="btn-close" @click="closeModal()" aria-label="Close"></button>
            </div>
            <div class="modal-body p-4">
                <form @submit.prevent="save()">
                    <div class="mb-3">
                        <label for="name" class="form-label">Nama Unit <span class="text-danger">*</span></label>
                        <input
                            type="text"
                            class="form-control"
                            id="name"
                            x-model="form.name"
                            x-ref="unitNameInput"
                            required
                        >
                        <div x-show="errors.name" class="text-danger small mt-1" x-text="errors.name"></div>
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
function unitQuickAddModal() {
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
            this.$nextTick(() => {
                this.$refs.unitNameInput.focus();
            });
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
                const response = await fetch('/api/units', {
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
                        throw new Error(data.message || 'Failed to create unit');
                    }
                    return;
                }

                // Dispatch success event
                window.dispatchEvent(new CustomEvent('unitCreated', { detail: data }));

                this.closeModal();

                // Show success message
                Swal.fire({
                    icon: 'success',
                    title: 'Berhasil!',
                    text: 'Unit berhasil ditambahkan!',
                    timer: 2000,
                    showConfirmButton: false
                });
            } catch (error) {
                console.error('Error creating unit:', error);
                Swal.fire({
                    icon: 'error',
                    title: 'Gagal!',
                    text: 'Gagal menambahkan unit. Silakan coba lagi.',
                    confirmButtonText: 'OK'
                });
            } finally {
                this.saving = false;
            }
        }
    };
}
</script>

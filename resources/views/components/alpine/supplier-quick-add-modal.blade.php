@props([
    'paymentTerms' => []
])

<div
    x-data="supplierQuickAddModal()"
    x-show="showModal"
    x-cloak
    class="modal fade"
    tabindex="-1"
    style="display: none;"
    @open-supplier-modal.window="openModal()"
>
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Tambah Pemasok Baru</h5>
                <button type="button" class="btn-close" @click="closeModal()" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <form @submit.prevent="save()">
                    <div class="mb-3">
                        <label for="supplier_name" class="form-label">Nama Pemasok <span class="text-danger">*</span></label>
                        <input
                            type="text"
                            class="form-control"
                            id="supplier_name"
                            x-model="form.supplier_name"
                            required
                        >
                        <div x-show="errors.supplier_name" class="text-danger small mt-1" x-text="errors.supplier_name"></div>
                    </div>

                    <div class="mb-3">
                        <label for="contact_name" class="form-label">Nama Kontak</label>
                        <input
                            type="text"
                            class="form-control"
                            id="contact_name"
                            x-model="form.contact_name"
                        >
                    </div>

                    <div class="mb-3">
                        <label for="email" class="form-label">Email</label>
                        <input
                            type="email"
                            class="form-control"
                            id="email"
                            x-model="form.email"
                        >
                        <div x-show="errors.email" class="text-danger small mt-1" x-text="errors.email"></div>
                    </div>

                    <div class="mb-3">
                        <label for="phone" class="form-label">Telepon</label>
                        <input
                            type="text"
                            class="form-control"
                            id="phone"
                            x-model="form.phone"
                        >
                    </div>

                    <div class="mb-3">
                        <label for="address" class="form-label">Alamat</label>
                        <textarea
                            class="form-control"
                            id="address"
                            rows="3"
                            x-model="form.address"
                        ></textarea>
                    </div>

                    <div class="mb-3">
                        <label for="payment_term_id" class="form-label">Term Pembayaran</label>
                        <select
                            class="form-control"
                            id="payment_term_id"
                            x-model="form.payment_term_id"
                        >
                            <option value="">Pilih Term Pembayaran</option>
                            <template x-for="term in paymentTerms" :key="term.id">
                                <option :value="term.id" x-text="term.name"></option>
                            </template>
                        </select>
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
function supplierQuickAddModal() {
    return {
        showModal: false,
        saving: false,
        paymentTerms: @js($paymentTerms ?? []),
        form: {
            supplier_name: '',
            contact_name: '',
            email: '',
            phone: '',
            address: '',
            payment_term_id: ''
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
                supplier_name: '',
                contact_name: '',
                email: '',
                phone: '',
                address: '',
                payment_term_id: ''
            };
            this.errors = {};
            this.saving = false;
        },

        async save() {
            this.saving = true;
            this.errors = {};

            try {
                const response = await fetch('/api/suppliers', {
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
                        throw new Error(data.message || 'Failed to create supplier');
                    }
                    return;
                }

                // Dispatch success event
                window.dispatchEvent(new CustomEvent('supplierCreated', { detail: data }));

                this.closeModal();

                // Show success message
                if (window.toast) {
                    window.toast('Pemasok berhasil ditambahkan!', 'success');
                }
            } catch (error) {
                console.error('Error creating supplier:', error);
                if (window.toast) {
                    window.toast('Gagal menambahkan pemasok. Silakan coba lagi.', 'error');
                }
            } finally {
                this.saving = false;
            }
        }
    };
}
</script>
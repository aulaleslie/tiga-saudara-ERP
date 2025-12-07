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
                <label for="supplier_name">Pemasok <span class="text-danger">*</span></label>
                <div class="d-flex">
                    <div class="flex-grow-1">
                        <livewire:auto-complete.supplier-loader wire:key="purchase-supplier-loader"/>
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
                <livewire:components.searchable-select
                    name="payment_term"
                    label="Term Pembayaran"
                    :model-class="'Modules\Purchase\Entities\PaymentTerm'"
                    :selected="$payment_term"
                    placeholder="Cari term pembayaran..."
                    required="true"
                    quickAddEntity="term pembayaran"
                    quickAddPermission="purchases.create"
                    quickAddModalEvent="openPaymentTermModal"
                    quickAddTooltip="Tambah term pembayaran baru"
                    listenForCreatedEvent="paymentTermCreated"
                    wire:key="purchase-payment-term-select"
                />
                @error('payment_term')
                <div class="text-danger">{{ $message }}</div> @enderror
            </div>

            <div class="col-lg-6 mb-3">
                <label for="tags">Tag Pembelian</label>
                <livewire:utils.tag-selector :initial-tags="$tags ?? []" wire:key="purchase-tag-selector" />
            </div>
        </div>

        <!-- Product Cart -->
        <div class="my-3">
            <livewire:purchase.product-cart :cartInstance="'purchase'" wire:key="purchase-product-cart"/>
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

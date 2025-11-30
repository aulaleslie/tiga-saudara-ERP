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
                <livewire:auto-complete.supplier-loader/>
                @error('supplier_id')
                <div class="text-danger">{{ $message }}</div> @enderror
            </div>

            <div class="col-lg-6 mb-3">
                <label for="supplier_purchase_number">Nomor Pembelian Pemasok</label>
                <input type="text" class="form-control" id="supplier_purchase_number" wire:model="supplier_purchase_number">
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
                <label for="payment_term">Term Pembayaran <span class="text-danger">*</span></label>
                <select id="payment_term" class="form-control" wire:model.lazy="payment_term">
                    <option value="">Pilih Term Pembayaran</option>
                    @foreach($paymentTerms as $term)
                        <option value="{{ (int) $term->id }}">{{ $term->name }}</option>
                    @endforeach
                </select>
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
            <livewire:purchase.product-cart :cartInstance="'purchase'"/>
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
            <button type="button" class="btn btn-primary" id="submitWithConfirmation">
                Buat Pembelian <i class="bi bi-check"></i>
            </button>
            <a href="{{ route('purchases.index') }}" class="btn btn-secondary">Kembali</a>
        </div>
    </form>
</div>

<div class="card-body">
    <div>
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
                <livewire:modules.people.supplier-search-dropdown
                    name="supplier_id"
                    placeholder="Pilih pemasok..."
                    :allow-create="true"
                    :error="$errors->first('supplier_id')"
                    wire:key="purchase-supplier-dropdown"
                />
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
                <input type="date" class="form-control" id="date" wire:model.live="date">
                @error('date')
                <div class="text-danger">{{ $message }}</div> @enderror
            </div>

            <!-- Jatuh Tempo -->
            <div class="col-lg-6 mb-3">
                <label for="due_date">Tanggal Jatuh Tempo <span class="text-danger">*</span></label>
                <input type="date" class="form-control" id="due_date" wire:model.live="due_date">
                @error('due_date')
                <div class="text-danger">{{ $message }}</div> @enderror
            </div>

            <!-- Payment Term -->
            <div class="col-lg-6 mb-3">
                <label for="payment_term_search">Term Pembayaran <span class="text-danger">*</span></label>
                <livewire:modules.purchase.payment-term-search-dropdown
                    name="payment_term"
                    placeholder="Pilih term pembayaran..."
                    :allow-create="true"
                    :error="$errors->first('payment_term')"
                    wire:key="purchase-payment-term-dropdown"
                />
            </div>

            <div class="col-lg-6 mb-3">
                <label for="tags">Tag Pembelian</label>
                <livewire:utils.tag-selector :initial-tags="$tags ?? []" wire:key="purchase-tag-selector" />
            </div>
        </div>

        <!-- Product Cart -->
        <div class="my-3">
            <livewire:purchase.product-cart :cartInstance="'purchase'" wire:key="purchase-product-cart" />
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
            <button type="button" class="btn btn-primary" 
                wire:loading.attr="disabled"
                x-data
                @click="
                    const supplierId = document.querySelector('input[name=supplier_id]')?.value || null;
                    const paymentTerm = document.querySelector('input[name=payment_term]')?.value || null;
                    $wire.submit(supplierId, paymentTerm);
                ">
                <span wire:loading wire:target="submit" class="spinner-border spinner-border-sm mr-2" role="status" aria-hidden="true"></span>
                <span wire:loading wire:target="submit">Memproses…</span>
                <span wire:loading.remove wire:target="submit">
                    Buat Pembelian <i class="bi bi-check ml-1"></i>
                </span>
            </button>
            <a href="{{ route('purchases.index') }}" class="btn btn-secondary">Kembali</a>
        </div>
    </div>
</div>





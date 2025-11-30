<div class="card-body">
    <form wire:submit.prevent="submit">
        <div class="form-row">
            <div class="col-lg-6 mb-3">
                <label>Referensi</label>
                <input type="text" class="form-control" readonly wire:model="reference">
            </div>

            <div class="col-lg-6 mb-3">
                <label>Pemasok <span class="text-danger">*</span></label>
                <livewire:auto-complete.supplier-loader :supplierId="$supplier_id"/>
                @error('supplier_id')
                <div class="text-danger">{{ $message }}</div> @enderror
            </div>

            <div class="col-lg-6 mb-3">
                <label>Nomor Pembelian Supplier</label>
                <input type="text" class="form-control" wire:model="supplier_purchase_number" placeholder="Opsional">
                @error('supplier_purchase_number')
                <div class="text-danger">{{ $message }}</div> @enderror
            </div>

            <div class="col-lg-6 mb-3">
                <label>Tanggal</label>
                <input type="date" class="form-control" wire:model="date">
                @error('date')
                <div class="text-danger">{{ $message }}</div> @enderror
            </div>

            <div class="col-lg-6 mb-3">
                <label>Jatuh Tempo</label>
                <input type="date" class="form-control" wire:model="due_date">
                @error('due_date')
                <div class="text-danger">{{ $message }}</div> @enderror
            </div>

            <div class="col-lg-6 mb-3">
                <label>Term Pembayaran</label>
                <select class="form-control" wire:model="payment_term">
                    <option value="">Pilih Term</option>
                    @foreach($paymentTerms as $term)
                        <option value="{{ $term->id }}">{{ $term->name }}</option>
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

        <div class="my-3">
            <livewire:purchase.product-cart :cartInstance="'purchase'" :data="$purchase"/>
        </div>

        <div class="form-group">
            <label>Catatan</label>
            <textarea class="form-control" rows="4" wire:model="note"></textarea>
            @error('note')
            <div class="text-danger">{{ $message }}</div> @enderror
        </div>

        <div class="mt-3">
            <button type="button" class="btn btn-primary" id="submitWithConfirmation">
                Perbarui Pembelian <i class="bi bi-check"></i>
            </button>
            <a href="{{ route('purchases.index') }}" class="btn btn-secondary">Kembali</a>
        </div>
    </form>
</div>

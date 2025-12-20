<div class="card-body">
    <form wire:submit.prevent="update">
        <div class="form-row">
            <!-- Referensi -->
            <div class="col-lg-6 mb-3">
                <label for="reference">Referensi</label>
                <input id="reference"
                       type="text"
                       class="form-control"
                       wire:model="reference"
                       readonly>
            </div>

            <!-- Pelanggan -->
            <div class="col-lg-6 mb-3">
                <label for="customer_search">Pelanggan <span class="text-danger">*</span></label>
                <livewire:modules.people.customer-search-dropdown
                    name="customer_id"
                    placeholder="Pilih pelanggan..."
                    :selected="$customerId"
                    :allow-create="true"
                    :error="$errors->first('customerId')"
                    wire:key="sale-edit-customer-dropdown"
                />
            </div>

            <!-- Tanggal -->
            <div class="col-lg-6 mb-3">
                <label for="date">Tanggal <span class="text-danger">*</span></label>
                <input id="date"
                       type="date"
                       class="form-control @error('date') is-invalid @enderror"
                       wire:model.live="date">
                @error('date')
                <div class="invalid-feedback">{{ $message }}</div> @enderror
            </div>

            <!-- Jatuh Tempo -->
            <div class="col-lg-6 mb-3">
                <label for="dueDate">Tanggal Jatuh Tempo <span class="text-danger">*</span></label>
                <input id="dueDate"
                       type="date"
                       class="form-control @error('dueDate') is-invalid @enderror"
                       wire:model.live="dueDate">
                @error('dueDate')
                <div class="invalid-feedback">{{ $message }}</div> @enderror
            </div>

            <!-- Term Pembayaran -->
            <div class="col-lg-6 mb-3">
                <label for="payment_term_search">Term Pembayaran <span class="text-danger">*</span></label>
                <livewire:modules.purchase.payment-term-search-dropdown
                    name="payment_term"
                    placeholder="Pilih term pembayaran..."
                    :selected="$paymentTermId"
                    :allow-create="true"
                    :error="$errors->first('paymentTermId')"
                    wire:key="sale-edit-payment-term-dropdown"
                />
            </div>
        </div>

        <!-- Keranjang & subtotal (repopulated from mount) -->
        <livewire:sale.product-cart cartInstance="sale" :data="$sale"/>

        <!-- Catatan -->
        <div class="form-group mt-3">
            <label for="note">Catatan</label>
            <textarea id="note"
                      class="form-control @error('note') is-invalid @enderror"
                      wire:model="note"
                      rows="3"></textarea>
            @error('note')
            <div class="invalid-feedback">{{ $message }}</div> @enderror
        </div>

        <div class="mt-3">
            <button type="button"
                    class="btn btn-primary"
                    id="submitWithConfirmation">
                Perbaharui Penjualan <i class="bi bi-check"></i>
            </button>
            <a href="{{ route('sales.index') }}" class="btn btn-secondary">Kembali</a>
        </div>
    </form>
</div>

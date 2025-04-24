<div class="card-body">
    <form wire:submit.prevent="submit">
        <div class="form-row">
            <!-- Referensi -->
            <div class="col-lg-6 mb-3">
                <label for="reference">Referensi</label>
                <input id="reference"
                       type="text"
                       class="form-control @error('reference') is-invalid @enderror"
                       wire:model="reference"
                       readonly>
                @error('reference') <div class="invalid-feedback">{{ $message }}</div> @enderror
            </div>

            <!-- Pelanggan -->
            <div class="col-lg-6 mb-3">
                <label for="customer">Pelanggan</label>
                <livewire:auto-complete.customer-loader :customerId="$customerId" />
                @error('customerId') <div class="text-danger">{{ $message }}</div> @enderror
            </div>

            <!-- Tanggal -->
            <div class="col-lg-6 mb-3">
                <label for="date">Tanggal</label>
                <input id="date"
                       type="date"
                       class="form-control @error('date') is-invalid @enderror"
                       wire:model="date">
                @error('date') <div class="invalid-feedback">{{ $message }}</div> @enderror
            </div>

            <!-- Jatuh Tempo -->
            <div class="col-lg-6 mb-3">
                <label for="dueDate">Tanggal Jatuh Tempo</label>
                <input id="dueDate"
                       type="date"
                       class="form-control @error('dueDate') is-invalid @enderror"
                       wire:model="dueDate">
                @error('dueDate') <div class="invalid-feedback">{{ $message }}</div> @enderror
            </div>

            <!-- Term Pembayaran -->
            <div class="col-lg-6 mb-3">
                <label for="paymentTermId">Term Pembayaran</label>
                <select id="paymentTermId"
                        class="form-control @error('paymentTermId') is-invalid @enderror"
                        wire:model="paymentTermId">
                    <option value="">Pilih Term Pembayaran</option>
                    @foreach($paymentTerms as $term)
                        <option value="{{ $term->id }}">{{ $term->name }}</option>
                    @endforeach
                </select>
                @error('paymentTermId') <div class="invalid-feedback">{{ $message }}</div> @enderror
            </div>
        </div>

        <!-- Keranjang & subtotal -->
        <livewire:sale.product-cart cartInstance="sale" />

        <!-- Catatan -->
        <div class="form-group mt-3">
            <label for="note">Catatan</label>
            <textarea id="note"
                      class="form-control @error('note') is-invalid @enderror"
                      wire:model="note"
                      rows="3"></textarea>
            @error('note') <div class="invalid-feedback">{{ $message }}</div> @enderror
        </div>

{{--        <button type="submit" class="btn btn-primary mt-2">--}}
{{--            Simpan Penjualan--}}
{{--        </button>--}}

        <div class="mt-3">
            <button type="button" class="btn btn-primary" id="submitWithConfirmation">
                Buat Penjualan <i class="bi bi-check"></i>
            </button>
            <a href="{{ route('sales.index') }}" class="btn btn-secondary">Kembali</a>
        </div>
    </form>
</div>

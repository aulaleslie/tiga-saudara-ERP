<div class="form-row">
    <!-- Referensi -->
    <div class="col-lg-6">
        <div class="form-group">
            <label for="reference">Referensi <span class="text-danger">*</span></label>
            <input type="text" class="form-control" name="reference" id="reference" wire:model="reference" readonly>
            @error('reference') <div class="invalid-feedback">{{ $message }}</div> @enderror
        </div>
    </div>

    <!-- Pelanggan -->
    <div class="col-lg-6">
        <div class="form-group">
            <label for="customer">Pelanggan</label>
            <!-- This is your auto-complete component which should emit a "customerSelected" event -->
            <livewire:auto-complete.customer-loader />
            @error('customerId') <span class="text-danger">{{ $message }}</span> @enderror
        </div>
    </div>

    <!-- Tanggal -->
    <div class="col-lg-6">
        <div class="form-group">
            <label for="date">Tanggal <span class="text-danger">*</span></label>
            <input type="date" class="form-control" name="date" id="date" wire:model="date" required>
            @error('date') <div class="invalid-feedback">{{ $message }}</div> @enderror
        </div>
    </div>

    <!-- Tanggal Jatuh Tempo -->
    <div class="col-lg-6">
        <div class="form-group">
            <label for="due_date">Tanggal Jatuh Tempo <span class="text-danger">*</span></label>
            <input type="date" class="form-control" name="due_date" id="due_date" wire:model="dueDate" required>
            @error('due_date') <div class="invalid-feedback">{{ $message }}</div> @enderror
        </div>
    </div>

    <input type="hidden" name="customer_id" value="{{ $customerId }}">
    <!-- Term Pembayaran -->
    <div class="col-lg-6">
        <div class="form-group">
            <label for="payment_term">Term Pembayaran <span class="text-danger">*</span></label>
            <select id="payment_term_id"
                    class="form-control"
                    name="payment_term_id"
                    wire:model="paymentTermId"
                    wire:change="paymentTermChanged"
                    required>
                <option value="">Pilih Term Pembayaran</option>
                @foreach($paymentTerms as $term)
                    <option value="{{ $term->id }}" data-longevity="{{ $term->longevity }}">
                        {{ $term->name }}
                    </option>
                @endforeach
            </select>
            @error('paymentTermId') <div class="invalid-feedback">{{ $message }}</div> @enderror
        </div>
    </div>
</div>


<div class="card-body">
    @php
        // Post-dispatch: only monetary inputs stay live. These locks are a
        // convenience for the user; the server re-derives and enforces the mode.
        $monetaryOnly = $editMode === \Modules\Sale\Entities\Sale::EDIT_MODE_MONETARY_ONLY;
    @endphp

    @if($monetaryOnly)
        <div class="alert alert-warning d-flex align-items-center" role="alert">
            <i class="bi bi-lock-fill mr-2"></i>
            <div>
                <strong>Mode Edit Moneter.</strong>
                Barang sudah dikirim, sehingga hanya harga, diskon, dan pajak yang dapat diubah.
                Kuantitas, produk, pelanggan, tanggal, dan pembayaran terkunci.
            </div>
        </div>
    @endif

    <form wire:submit.prevent="update">
        <div class="form-row">
            <!-- Business Selector (if user has override permission and document is draft) -->
            @php
                try {
                    $hasOverridePermission = auth()->user()->hasRole('Super Admin')
                        || auth()->user()->hasPermissionTo('documents.business.override');
                } catch (\Exception $e) {
                    $hasOverridePermission = false;
                }
            @endphp
            @if($hasOverridePermission && $sale->status === \Modules\Sale\Entities\Sale::STATUS_DRAFTED)
            <div class="col-lg-6 mb-3">
                <livewire:business-selector
                    :selectedSettingId="$selectedSettingId"
                    :isRequired="true"
                    selectId="sale-edit-business-selector"
                    wire:key="sale-edit-business-selector"
                />
            </div>
            @endif

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
                @if(! $monetaryOnly)
                <livewire:modules.people.customer-search-dropdown
                    name="customer_id"
                    placeholder="Pilih pelanggan..."
                    :selected="$customerId"
                    :allow-create="true"
                    :dispatch-on-create="true"
                    :error="$errors->first('customerId')"
                    wire:key="sale-edit-customer-dropdown"
                />
                @else
                <input type="text" class="form-control" readonly value="{{ $sale->customer->customer_name ?? $sale->customer->contact_name ?? 'Pelanggan' }}">
                @endif
            </div>

            <!-- Tanggal -->
            <div class="col-lg-6 mb-3">
                <label for="date">Tanggal <span class="text-danger">*</span></label>
                <input id="date"
                       type="date"
                       class="form-control @error('date') is-invalid @enderror"
                       wire:model.live="date"
                       @disabled($monetaryOnly)>
                @error('date')
                <div class="invalid-feedback">{{ $message }}</div> @enderror
            </div>

            <!-- Jatuh Tempo -->
            <div class="col-lg-6 mb-3">
                @php
                    $dueDateInputValue = $dueDateForView ?? '';
                    $dueDateFieldKey = 'sale-edit-due-date-field-' . $dueDateRenderVersion . '-' . ($dueDateInputValue !== '' ? $dueDateInputValue : 'empty');
                @endphp
                <label for="dueDate">Tanggal Jatuh Tempo <span class="text-danger">*</span></label>
                <input id="dueDate"
                       type="date"
                       class="form-control @error('dueDate') is-invalid @enderror"
                       wire:model.live="dueDate"
                       wire:key="{{ $dueDateFieldKey }}"
                       value="{{ $dueDateInputValue }}"
                       @disabled($monetaryOnly)>
                @error('dueDate')
                <div class="invalid-feedback">{{ $message }}</div> @enderror
            </div>

            <!-- Term Pembayaran -->
            <div class="col-lg-6 mb-3">
                <label for="payment_term_search">Term Pembayaran <span class="text-danger">*</span></label>
                @if(! $monetaryOnly)
                <livewire:modules.purchase.payment-term-search-dropdown
                    name="payment_term"
                    placeholder="Pilih term pembayaran..."
                    :selected="$paymentTermId"
                    wire:model.live="paymentTermId"
                    :allow-create="true"
                    :error="$errors->first('paymentTermId')"
                    wire:key="sale-edit-payment-term-dropdown"
                />
                @else
                <input type="text" class="form-control" readonly value="{{ $sale->paymentTerm->name ?? 'Term' }}">
                @endif
            </div>

            <!-- Nomor Faktur Pajak -->
            @if($isPkp)
            <div class="col-lg-6 mb-3">
                <label for="tax_ref_no">Nomor Faktur Pajak</label>
                <input type="text" class="form-control" id="tax_ref_no" wire:model="tax_ref_no" placeholder="Opsional" @disabled($monetaryOnly)>
                @error('tax_ref_no')
                    <div class="invalid-feedback d-block">{{ $message }}</div>
                @enderror
            </div>
            @endif

            <!-- Tag Penjualan -->
            <div class="col-lg-6 mb-3">
                <label for="tags">Tag Penjualan</label>
                @if(! $monetaryOnly)
                <livewire:utils.tag-selector :initial-tags="$tags ?? []" wire:key="edit-sale-tag-selector" />
                @else
                <input type="text" class="form-control" readonly value="{{ implode(', ', $tags ?? []) }}">
                @endif
            </div>
        </div>

        <!-- Keranjang & subtotal (repopulated from mount) -->
        <livewire:sale.product-cart cartInstance="sale" :data="$sale" :selectedSettingId="$selectedSettingId" wire:key="edit-sale-product-cart-{{ $selectedSettingId }}"/>

        <!-- Catatan -->
        <div class="form-group mt-3">
            <label for="note">Catatan</label>
            <textarea id="note"
                      class="form-control @error('note') is-invalid @enderror"
                      wire:model="note"
                      @disabled($monetaryOnly)
                      rows="3"></textarea>
            @error('note')
            <div class="invalid-feedback">{{ $message }}</div> @enderror
        </div>

        <div class="mt-3">
            <button
                type="button"
                class="btn btn-primary d-inline-flex align-items-center submit-lock-btn"
                id="submitWithConfirmation"
                data-processing-text="Memproses…"
                data-default-text="Perbaharui Penjualan"
            >
                <span class="spinner-border spinner-border-sm mr-2 d-none button-spinner" role="status" aria-hidden="true"></span>
                <span class="button-text">Perbaharui Penjualan</span>
                <i class="bi bi-check ml-1"></i>
            </button>
            <a href="{{ route('sales.index') }}" class="btn btn-secondary">Kembali</a>
        </div>
    </form>
</div>

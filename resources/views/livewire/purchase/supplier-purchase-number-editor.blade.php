<div>
    <div class="d-flex align-items-start mb-2">
        <div class="flex-grow-1">
            <div>Nomor Pembelian Supplier:</div>
            <div class="fw-semibold">{{ $supplierPurchaseNumber ?: '-' }}</div>
        </div>

        @if($canEdit && ! $editing)
            <button type="button"
                    class="btn btn-link btn-sm text-decoration-none"
                    wire:click="startEditing"
                    wire:loading.attr="disabled">
                <i class="bi bi-pencil"></i> Ubah
            </button>
        @endif
    </div>

    @if ($editing)
        <div class="border rounded p-2 bg-light">
            <label for="supplier_purchase_number" class="form-label mb-1">Perbarui Nomor Pembelian Supplier</label>
            <input id="supplier_purchase_number" type="text" class="form-control form-control-sm"
                   wire:model.defer="supplierPurchaseNumber"
                   placeholder="Opsional" />
            @error('supplierPurchaseNumber')
                <div class="text-danger small mt-1">{{ $message }}</div>
            @enderror

            <div class="mt-2 d-flex align-items-center">
                <button class="btn btn-primary btn-sm" type="button" wire:click="save" wire:loading.attr="disabled">
                    Simpan
                </button>
                <button class="btn btn-secondary btn-sm ms-2" type="button" wire:click="cancelEdit" wire:loading.attr="disabled">
                    Batal
                </button>
            </div>
        </div>
    @endif
</div>

<div>
    <div class="d-flex align-items-start mb-2">
        <div class="flex-grow-1">
            <div>Nomor Faktur Pajak:</div>
            <div class="fw-semibold">{{ $taxRefNo ?: '-' }}</div>
        </div>

        @if($canEdit && ! $editing && ! $isArchived)
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
            <label for="tax_ref_no" class="form-label mb-1">Perbarui Nomor Faktur Pajak</label>
            <input id="tax_ref_no" type="text" class="form-control form-control-sm"
                   wire:model.defer="taxRefNo"
                   placeholder="Opsional" />
            @error('taxRefNo')
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

<div class="container-fluid">
    {{-- Purchase Return Form --}}
    <form id="purchase-return-form" wire:submit.prevent="submit">
        @csrf

        {{-- Supplier & Date Inputs --}}
        <div class="form-row">
            <div class="col-lg-6">
                <div class="form-group">
                    <label for="supplier">Pemasok</label>
                    <livewire:auto-complete.supplier-loader/>
                    @error('supplier_id') <span class="text-danger">{{ $message }}</span> @enderror
                </div>
            </div>
            <div class="col-lg-6">
                <div class="form-group">
                    <label for="date">Tanggal Retur</label>
                    <input type="date" class="form-control" wire:model.defer="date" required>
                    @error('date') <span class="text-danger">{{ $message }}</span> @enderror
                </div>
            </div>
        </div>

        {{-- Livewire Components for Product Table --}}
        @error('rows') <span class="text-danger">{{ $message }}</span> @enderror
        <livewire:purchase-return.purchase-return-table />

        {{-- Note Input --}}
        <div class="form-group mt-3">
            <label for="note">Catatan</label>
            <textarea id="note" class="form-control" wire:model.defer="note" rows="3" placeholder="Tambahkan catatan (Opsional)"></textarea>
            @error('note') <span class="text-danger">{{ $message }}</span> @enderror
        </div>

        {{-- Submit Button --}}
        <div class="mt-3">
            <button type="submit" class="btn btn-primary" wire:loading.attr="disabled">Proses Retur</button>
            <a href="{{ route('purchase-returns.index') }}" class="btn btn-secondary">Kembali</a>
        </div>
    </form>
</div>

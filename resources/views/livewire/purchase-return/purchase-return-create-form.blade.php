<div class="container-fluid">
    {{-- Alerts --}}
    @include('utils.alerts')

    {{-- Purchase Return Form --}}
    <form id="purchase-return-form" action="{{ route('purchase-returns.store') }}" method="POST">
        @csrf

        {{-- Supplier & Date Inputs --}}
        <div class="form-row">
            <div class="col-lg-6">
                <div class="form-group">
                    <label for="supplier">Pemasok</label>
                    <livewire:auto-complete.supplier-loader/>
                </div>
            </div>
            <div class="col-lg-6">
                <div class="form-group">
                    <label for="date">Tanggal Retur</label>
                    <input type="date" class="form-control" name="date" wire:model="date" required>
                </div>
            </div>
        </div>

        {{-- Livewire Components for Product Table and Search --}}
        <livewire:purchase-return.purchase-return-table :supplier_id="$supplier_id" />

        {{-- Submit Button --}}
        <div class="mt-3">
            <button type="submit" class="btn btn-primary">Proses Retur</button>
            <a href="{{ route('purchase-returns.index') }}" class="btn btn-secondary">Kembali</a>
        </div>
    </form>
</div>

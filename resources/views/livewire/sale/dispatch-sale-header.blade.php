<div>
    <div class="mb-3">
        <h2>Penjualan #{{ $sale->reference }}</h2>
    </div>

    <div class="card mb-3">
        <div class="card-header">Detail Penjualan</div>
        <div class="card-body">
            <p><strong>Pelanggan:</strong> {{ $sale->customer->contact_name }}</p>
            <p><strong>Tanggal:</strong> {{ $sale->date }}</p>
            <p><strong>Total Jumlah:</strong> Rp {{ number_format($sale->total_amount, 2) }}</p>
        </div>
    </div>

    <div class="card p-3">
        <div class="row">
            <div class="col-sm-6">
                <div class="form-group">
                    <label for="dispatch_date">Tanggal Pengiriman</label>
                    <input type="datetime-local" id="dispatch_date" class="form-control" wire:model="dispatch_date">
                </div>
            </div>
        </div>
    </div>
</div>

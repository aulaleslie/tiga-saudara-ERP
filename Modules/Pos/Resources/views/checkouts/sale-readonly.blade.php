<div class="row mb-4">
    <!-- Informasi Pelanggan -->
    <div class="col-sm-6 mb-3 mb-md-0">
        <h5 class="mb-2 border-bottom pb-2">Informasi Pelanggan:</h5>
        <div><strong>{{ $sale->customer->customer_name ?? '-' }}</strong></div>
    </div>
    <!-- Info Faktur -->
    <div class="col-sm-6 mb-3 mb-md-0">
        <h5 class="mb-2 border-bottom pb-2">Info Faktur:</h5>
        <div>Faktur: <strong>{{ $sale->reference }}</strong></div>
        <div>Tanggal: {{ \Carbon\Carbon::parse($sale->date)->format('d M, Y') }}</div>
        <div>Status: <strong>{{ $sale->status }}</strong></div>
        <div>Status Pembayaran: <strong>{{ $sale->payment_status }}</strong></div>
    </div>
</div>

<div class="table-responsive-sm">
    <table class="table table-striped">
        <thead>
        <tr>
            <th class="align-middle" style="width: 15%;">Produk</th>
            <th class="align-middle">Harga Satuan</th>
            <th class="align-middle">Kuantitas</th>
            <th class="align-middle">Diskon</th>
            <th class="align-middle">Jumlah Total</th>
        </tr>
        </thead>
        <tbody>
        @foreach($sale->saleDetails as $detail)
            <tr>
                <td class="align-middle">
                    {{ $detail->product_name }} <br>
                    <span class="badge bg-success">{{ $detail->product_code }}</span>
                </td>
                <td class="align-middle">{{ format_currency($detail->price ?? $detail->unit_price) }}</td>
                <td class="align-middle">{{ (float)($detail->quantity ?? $detail->qty) }}</td>
                <td class="align-middle">{{ format_currency($detail->product_discount_amount ?? $detail->line_discount_amount ?? 0) }}</td>
                <td class="align-middle">{{ format_currency($detail->sub_total) }}</td>
            </tr>
        @endforeach
        </tbody>
    </table>
</div>

<div class="row">
    <div class="col-lg-4 col-sm-5 ml-md-auto">
        <table class="table">
            <tbody>
            <tr>
                <td class="left"><strong>Diskon ({{ $sale->discount_percentage ?? 0 }}%)</strong></td>
                <td class="right">{{ format_currency($sale->discount_amount ?? 0) }}</td>
            </tr>
            @if(($sale->tax_amount ?? 0) > 0)
                <tr>
                    <td class="left"><strong>Pajak</strong></td>
                    <td class="right">{{ format_currency($sale->tax_amount) }}</td>
                </tr>
            @endif
            <tr>
                <td class="left"><strong>Total Keseluruhan</strong></td>
                <td class="right"><strong>{{ format_currency($sale->total_amount) }}</strong></td>
            </tr>
            </tbody>
        </table>
    </div>
</div>

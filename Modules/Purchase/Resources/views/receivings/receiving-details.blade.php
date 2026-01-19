<div class="p-3 bg-light border">
    <h6>📦 Produk yang Diterima:</h6>
    <table class="table table-sm">
        <thead>
        <tr>
            <th>Produk</th>
            <th>Jumlah</th>
            <th>Serial Numbers</th>
        </tr>
        </thead>
        <tbody>
        @foreach($data->receivedNoteDetails as $detail)
            <tr>
                <td>{{ optional($detail->purchaseDetail)->product_name ?? 'Unknown' }}</td>
                <td>{{ $detail->quantity_received }}</td>
                <td>
                    @if($detail->productSerialNumbers->isNotEmpty())
                        <ul class="list-unstyled mb-0">
                            @foreach($detail->productSerialNumbers as $serial)
                                <li class="badge bg-info me-1">{{ $serial->serial_number }}</li>
                            @endforeach
                        </ul>
                    @elseif(!empty($detail->pending_serial_numbers))
                        <ul class="list-unstyled mb-0">
                            @foreach($detail->pending_serial_numbers as $serial)
                                <li class="badge bg-warning me-1">{{ $serial }}</li>
                            @endforeach
                            <small class="d-block text-muted mt-1">(Menunggu Persetujuan)</small>
                        </ul>
                    @else
                        <span class="text-muted">-</span>
                    @endif
                </td>
            </tr>
        @endforeach
        </tbody>
    </table>
</div>

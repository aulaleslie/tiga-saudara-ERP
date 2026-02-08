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
                    @php
                        $hasActiveSerials = $detail->productSerialNumbers->isNotEmpty();
                        $hasReturnedSerials = isset($detail->returnedSerialNumbers) && $detail->returnedSerialNumbers->isNotEmpty();
                    @endphp

                    @if($hasActiveSerials || $hasReturnedSerials)
                        <ul class="list-unstyled mb-0">
                            @if($hasActiveSerials)
                                @foreach($detail->productSerialNumbers as $serial)
                                    <li class="badge {{ !in_array($serial->status, [\Modules\Product\Entities\ProductSerialNumber::STATUS_ACTIVE, 'active']) ? 'bg-danger' : 'bg-info' }} me-1">
                                        {{ $serial->serial_number }}
                                    </li>
                                @endforeach
                            @endif

                            @if($hasReturnedSerials)
                                @foreach($detail->returnedSerialNumbers as $serial)
                                    <li class="badge bg-danger me-1" title="Returned">
                                        {{ $serial->serial_number }}
                                    </li>
                                @endforeach
                            @endif
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

<div class="p-3 bg-light border">
    <h6>📦 Produk yang Diterima:</h6>
    <table class="table table-sm">
        <thead>
        <tr>
            <th>Produk</th>
            <th>Jumlah</th>
            <th>Serial Numbers</th>
            <th>Catatan</th>
        </tr>
        </thead>
        <tbody>
        @foreach($data->receivedNoteDetails as $detail)
            <tr>
                <td>{{ optional($detail->purchaseDetail)->product_name ?? 'Unknown' }}</td>
                <td>
                    {{ $detail->quantity_received }}
                    @if($detail->uomNormalizationLines && $detail->uomNormalizationLines->count() > 0)
                        @foreach($detail->uomNormalizationLines as $normLine)
                            <div class="alert alert-info py-1 px-2 mt-2 mb-0 small">
                                <i class="cil-info"></i> Base UOM corrected from {{ optional($normLine->batch->oldBaseUnit)->name }} to {{ optional($normLine->batch->newBaseUnit)->name }} on {{ $normLine->batch->executed_at ? $normLine->batch->executed_at->format('Y-m-d') : $normLine->created_at->format('Y-m-d') }}.
                            </div>
                        @endforeach
                    @endif
                </td>
                <td>
                    @php
                        $regularSerials = $detail->productSerialNumbers ?? collect([]);
                        $returnedSerials = $detail->returnedSerialNumbers ?? collect([]);

                        // Deduplicate: If a serial is in both, prioritize the "returned" status for visual state
                        // but ensure it's not rendered twice.
                        $returnedSerialNumbers = $returnedSerials->pluck('serial_number')->toArray();
                        
                        $allSerials = $regularSerials->filter(function($s) use ($returnedSerialNumbers) {
                            return !in_array($s->serial_number, $returnedSerialNumbers);
                        })->map(function($s) {
                            $status = strtoupper((string) ($s->status ?? ''));
                            $badgeClass = 'bg-danger';
                            $title = $status !== '' ? $status : 'Returned';

                            if ($status === \Modules\Product\Entities\ProductSerialNumber::STATUS_ACTIVE) {
                                $badgeClass = 'bg-info';
                                $title = 'Active';
                            } elseif ($status === \Modules\Product\Entities\ProductSerialNumber::STATUS_SOLD) {
                                $badgeClass = 'bg-info';
                                $title = 'Sold';
                            } elseif ($status === \Modules\Product\Entities\ProductSerialNumber::STATUS_RETURN_IN_PROCESS) {
                                $badgeClass = 'bg-warning text-dark';
                                $title = 'Sedang diretur';
                            } elseif ($status === \Modules\Product\Entities\ProductSerialNumber::STATUS_RETURNED) {
                                $badgeClass = 'bg-danger';
                                $title = 'Returned';
                            }

                            return (object)[
                                'serial_number' => $s->serial_number,
                                'badge_class' => $badgeClass,
                                'title' => $title,
                            ];
                        })->concat($returnedSerials->map(function($s) {
                            return (object)[
                                'serial_number' => $s->serial_number,
                                'badge_class' => 'bg-danger',
                                'title' => 'Returned'
                            ];
                        }))->sortBy('serial_number');
                    @endphp
                    @if($allSerials->isNotEmpty())
                        <ul class="list-unstyled mb-0">
                            @foreach($allSerials as $serial)
                                <li class="badge {{ $serial->badge_class }} me-1" title="{{ $serial->title }}">
                                    {{ $serial->serial_number }}
                                </li>
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
                <td>{{ $detail->note ?? '-' }}</td>
            </tr>
        @endforeach
        </tbody>
    </table>
</div>

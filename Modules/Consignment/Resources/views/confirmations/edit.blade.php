@extends('layouts.app')

@section('title', 'Ubah Draft Konfirmasi Alokasi ' . $confirmation->confirmation_number)

@section('breadcrumb')
    <ol class="breadcrumb border-0 m-0">
        <li class="breadcrumb-item"><a href="{{ route('home') }}">Beranda</a></li>
        <li class="breadcrumb-item"><a href="{{ route('consignments.confirmations.index') }}">Konfirmasi Alokasi</a></li>
        <li class="breadcrumb-item active">Ubah Draft</li>
    </ol>
@endsection

@section('content')
    <div class="container-fluid">
        <div class="card border-0 shadow-sm">
            <div class="card-body">
                <h4 class="card-title">Ubah Draft Konfirmasi #{{ $confirmation->confirmation_number }}</h4>
                <p class="text-muted small">Supplier: {{ $confirmation->supplier->supplier_name }}</p>

                <form method="POST" action="{{ route('consignments.confirmations.update', $confirmation->id) }}">
                    @csrf
                    @method('PUT')

                    <div class="form-group">
                        <label>Catatan</label>
                        <textarea name="notes" class="form-control" rows="2">{{ $confirmation->notes }}</textarea>
                    </div>

                    <h5 class="mt-4">Rincian Baris Alokasi</h5>
                    <div class="table-responsive">
                        <table class="table table-bordered table-sm">
                            <thead class="thead-light">
                                <tr>
                                    <th>Pilih</th>
                                    <th>Ref Penjualan</th>
                                    <th>Lokasi</th>
                                    <th>Produk</th>
                                    <th>Sisa Eligible Qty</th>
                                    <th>Alokasi Qty</th>
                                </tr>
                            </thead>
                            <tbody>
                                @php
                                    $savedLines = $confirmation->lines->keyBy('consignment_sold_source_id');
                                @endphp
                                @forelse($eligibleSources as $idx => $src)
                                    @php
                                        $savedLine = $savedLines->get($src->id);
                                        $isChecked = $savedLine ? 'checked' : '';
                                        $allocatedQty = $savedLine ? $savedLine->allocated_base_quantity : $src->eligibility['remaining_quantity'];
                                        $savedReceipts = $savedLine ? $savedLine->receiptAllocations->keyBy('consignment_receiving_detail_id') : collect();
                                        $savedSerials = $savedLine ? $savedLine->serializedAllocations->keyBy('product_serial_number_id') : collect();
                                    @endphp
                                    <tr>
                                        <td>
                                            <input type="checkbox" name="lines[{{ $idx }}][selected]" value="1" {{ $isChecked }}>
                                            <input type="hidden" name="lines[{{ $idx }}][consignment_sold_source_id]" value="{{ $src->id }}">
                                            <input type="hidden" name="lines[{{ $idx }}][product_id]" value="{{ $src->product_id }}">
                                            <input type="hidden" name="lines[{{ $idx }}][location_id]" value="{{ $src->location_id }}">
                                        </td>
                                        <td>{{ $src->sale->reference ?? "Dispatch #{$src->dispatch_detail_id}" }}</td>
                                        <td>{{ $src->location->name ?? '-' }}</td>
                                        <td>{{ $src->product->product_name ?? '-' }}</td>
                                        <td>{{ number_format($src->eligibility['remaining_quantity'], 3) }}</td>
                                        <td>
                                            <input type="number" step="0.001" name="lines[{{ $idx }}][allocated_base_quantity]" class="form-control form-control-sm" value="{{ $allocatedQty }}">
                                        </td>
                                    </tr>
                                    @if(! empty($src->receipt_pools))
                                        <tr class="bg-light">
                                            <td></td>
                                            <td colspan="5">
                                                <small class="text-muted font-weight-bold"><i class="bi bi-box-seam"></i> Alokasi Lot Penerimaan (Receipt Pool):</small>
                                                @foreach($src->receipt_pools as $pIdx => $pool)
                                                    @php
                                                        if ($savedReceipts->has($pool['consignment_receiving_detail_id'])) {
                                                            $poolQty = $savedReceipts->get($pool['consignment_receiving_detail_id'])->allocated_base_quantity;
                                                        } else {
                                                            $poolQty = $isChecked ? 0 : ($pIdx === 0 ? min($src->eligibility['remaining_quantity'], $pool['remaining_quantity']) : 0);
                                                        }
                                                    @endphp
                                                    <div class="form-inline mt-1">
                                                        <input type="hidden" name="lines[{{ $idx }}][receipt_allocations][{{ $pIdx }}][consignment_receiving_detail_id]" value="{{ $pool['consignment_receiving_detail_id'] }}">
                                                        <label class="mr-2 small">Receiving {{ $pool['receiving_number'] }} (Capacity: {{ number_format($pool['remaining_quantity'], 3) }}):</label>
                                                        <input type="number" step="0.001" name="lines[{{ $idx }}][receipt_allocations][{{ $pIdx }}][allocated_base_quantity]" class="form-control form-control-sm" value="{{ $poolQty }}" style="width: 120px;">
                                                    </div>
                                                @endforeach
                                                @if(! empty($src->resolved_serials))
                                                    <div class="mt-2">
                                                        <small class="text-muted font-weight-bold"><i class="bi bi-barcode"></i> Serial Numbers Eligible:</small>
                                                        @foreach($src->resolved_serials as $sIdx => $snData)
                                                            @php
                                                                if ($savedLine) {
                                                                    $isSerialChecked = $savedSerials->has($snData['product_serial_number_id']) ? 'checked' : '';
                                                                } else {
                                                                    $isSerialChecked = 'checked';
                                                                }
                                                            @endphp
                                                            <div class="form-check form-inline mt-1 small">
                                                                <input type="checkbox" class="form-check-input" {{ $isSerialChecked }} name="lines[{{ $idx }}][serialized_allocations][{{ $sIdx }}][selected]" value="1" id="serial_{{ $idx }}_{{ $sIdx }}">
                                                                <input type="hidden" name="lines[{{ $idx }}][serialized_allocations][{{ $sIdx }}][product_serial_number_id]" value="{{ $snData['product_serial_number_id'] }}">
                                                                <input type="hidden" name="lines[{{ $idx }}][serialized_allocations][{{ $sIdx }}][consignment_receiving_detail_id]" value="{{ $snData['consignment_receiving_detail_id'] }}">
                                                                <label class="form-check-label mr-2" for="serial_{{ $idx }}_{{ $sIdx }}">Serial: <strong>{{ $snData['serial_number'] }}</strong> (Receiving: {{ $snData['receiving_number'] }})</label>
                                                            </div>
                                                        @endforeach
                                                    </div>
                                                @endif
                                            </td>
                                        </tr>
                                    @endif
                                @empty
                                    <tr>
                                        <td colspan="6" class="text-center text-muted py-3">Tidak ada sumber terjual konsinyasi yang eligible saat ini.</td>
                                    </tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>

                    <div class="mt-4">
                        <button type="submit" class="btn btn-primary"><i class="bi bi-check-lg"></i> Perbarui Draft</button>
                        <a href="{{ route('consignments.confirmations.show', $confirmation->id) }}" class="btn btn-secondary">Batal</a>
                    </div>
                </form>
            </div>
        </div>
    </div>
@endsection

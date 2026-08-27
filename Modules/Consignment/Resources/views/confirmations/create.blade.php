@extends('layouts.app')

@section('title', 'Buat Draft Konfirmasi Alokasi')

@section('breadcrumb')
    <ol class="breadcrumb border-0 m-0">
        <li class="breadcrumb-item"><a href="{{ route('home') }}">Beranda</a></li>
        <li class="breadcrumb-item"><a href="{{ route('consignments.confirmations.index') }}">Konfirmasi Alokasi</a></li>
        <li class="breadcrumb-item active">Buat Draft</li>
    </ol>
@endsection

@section('content')
    <div class="container-fluid">
        <div class="card border-0 shadow-sm">
            <div class="card-body">
                <h4 class="card-title">Buat Draft Konfirmasi Alokasi Penjualan (1 Supplier)</h4>
                <p class="text-muted small">Pilih supplier dan alokasikan sumber penjualan konsinyasi yang eligible.</p>

                <form method="POST" action="{{ route('consignments.confirmations.store') }}">
                    @csrf

                    <div class="form-row">
                        <div class="form-group col-md-6">
                            <label>Supplier <span class="text-danger">*</span></label>
                            <select name="supplier_id" class="form-control" onchange="if(this.value){ window.location.href='{{ route('consignments.confirmations.create') }}?supplier_id='+this.value; }" required>
                                <option value="">-- Pilih Supplier --</option>
                                @foreach($suppliers as $s)
                                    <option value="{{ $s->id }}" {{ ($selectedSupplierId ?? null) == $s->id ? 'selected' : '' }}>{{ $s->supplier_name }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div class="form-group col-md-6">
                            <label>Tanggal <span class="text-danger">*</span></label>
                            <input type="date" name="date" class="form-control" value="{{ date('Y-m-d') }}" required>
                        </div>
                    </div>

                    <div class="form-group">
                        <label>Catatan</label>
                        <textarea name="notes" class="form-control" rows="2" placeholder="Catatan opsional..."></textarea>
                    </div>

                    <h5 class="mt-4">Pilih Sumber Terjual</h5>
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
                                @forelse($eligibleSources as $idx => $src)
                                    <tr>
                                        <td>
                                            <input type="checkbox" name="lines[{{ $idx }}][selected]" value="1">
                                            <input type="hidden" name="lines[{{ $idx }}][consignment_sold_source_id]" value="{{ $src->id }}">
                                            <input type="hidden" name="lines[{{ $idx }}][product_id]" value="{{ $src->product_id }}">
                                            <input type="hidden" name="lines[{{ $idx }}][location_id]" value="{{ $src->location_id }}">
                                        </td>
                                        <td>{{ $src->sale->reference ?? "Dispatch #{$src->dispatch_detail_id}" }}</td>
                                        <td>{{ $src->location->name ?? '-' }}</td>
                                        <td>{{ $src->product->product_name ?? '-' }}</td>
                                        <td>{{ number_format($src->eligibility['remaining_quantity'], 3) }}</td>
                                        <td>
                                            <input type="number" step="0.001" name="lines[{{ $idx }}][allocated_base_quantity]" class="form-control form-control-sm" value="{{ $src->eligibility['remaining_quantity'] }}">
                                        </td>
                                    </tr>
                                    @if(! empty($src->receipt_pools))
                                        <tr class="bg-light">
                                            <td></td>
                                            <td colspan="5">
                                                <small class="text-muted font-weight-bold"><i class="bi bi-box-seam"></i> Alokasi Lot Penerimaan (Receipt Pool):</small>
                                                @foreach($src->receipt_pools as $pIdx => $pool)
                                                    <div class="form-inline mt-1">
                                                        <input type="hidden" name="lines[{{ $idx }}][receipt_allocations][{{ $pIdx }}][consignment_receiving_detail_id]" value="{{ $pool['consignment_receiving_detail_id'] }}">
                                                        <label class="mr-2 small">Receiving {{ $pool['receiving_number'] }} (Capacity: {{ number_format($pool['remaining_quantity'], 3) }}):</label>
                                                        <input type="number" step="0.001" name="lines[{{ $idx }}][receipt_allocations][{{ $pIdx }}][allocated_base_quantity]" class="form-control form-control-sm" value="{{ $pIdx === 0 ? min($src->eligibility['remaining_quantity'], $pool['remaining_quantity']) : 0 }}" style="width: 120px;">
                                                    </div>
                                                @endforeach
                                                @if(! empty($src->resolved_serials))
                                                    <div class="mt-2">
                                                        <small class="text-muted font-weight-bold"><i class="bi bi-barcode"></i> Serial Numbers Eligible:</small>
                                                        @foreach($src->resolved_serials as $sIdx => $snData)
                                                            <div class="form-check form-inline mt-1 small">
                                                                <input type="checkbox" class="form-check-input" checked name="lines[{{ $idx }}][serialized_allocations][{{ $sIdx }}][selected]" value="1" id="serial_{{ $idx }}_{{ $sIdx }}">
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
                        <button type="submit" class="btn btn-primary"><i class="bi bi-check-lg"></i> Simpan Draft</button>
                        <a href="{{ route('consignments.confirmations.index') }}" class="btn btn-secondary">Batal</a>
                    </div>
                </form>
            </div>
        </div>
    </div>
@endsection

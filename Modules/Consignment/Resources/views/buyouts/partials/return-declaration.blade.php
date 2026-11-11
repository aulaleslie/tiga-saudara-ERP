{{--
    Ownership split of returned NON-serialized units for sales partly acquired by a finalized consignment buyout.
    Serialized units are resolved from exact serial lineage and never appear here. Server-side validation in
    ConsignmentBuyoutReturnOwnershipService::declare() applies regardless of what this form shows.
    Expects: $saleReturn (Modules\SalesReturn\Entities\SaleReturn)
--}}
@php
    $declarationRows = app(\Modules\Consignment\Services\ConsignmentBuyoutReturnOwnershipService::class)->declarationContext($saleReturn);
    $declarationReceived = $saleReturn->received_at !== null
        || in_array(strtolower((string) $saleReturn->status), ['awaiting settlement', 'completed'], true);
    $declarationEditable = ! $declarationReceived
        && \Modules\Consignment\Http\Controllers\ConsignmentBuyoutController::mutationsEnabled()
        && \Illuminate\Support\Facades\Gate::allows('consignments.buyouts.acquire_unsold');
@endphp
@if(! empty($declarationRows))
    <div class="card border-0 shadow-sm mt-3" data-testid="buyout-return-declaration" id="buyout-return-declaration-{{ $saleReturn->id }}">
        <div class="card-header bg-white">
            <h6 class="mb-0">Kepemilikan Barang Retur (Pembelian Putus Konsinyasi)</h6>
            <small class="text-muted">
                Sebagian penjualan ini sudah dibeli putus dari pemasok. Tentukan berapa unit retur (tanpa nomor seri) yang
                termasuk unit milik perusahaan sebelum barang diterima. Unit bernomor seri ditentukan otomatis.
            </small>
        </div>
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-sm mb-0 align-middle">
                    <thead>
                    <tr>
                        <th>Produk</th>
                        <th class="text-end">Retur (tanpa seri)</th>
                        <th class="text-end">Terjual</th>
                        <th class="text-end">Dibeli putus</th>
                        <th class="text-end">Dibeli putus sudah diretur</th>
                        <th>Tujuan milik perusahaan</th>
                        <th>Unit milik perusahaan</th>
                    </tr>
                    </thead>
                    <tbody>
                    @foreach($declarationRows as $row)
                        <tr>
                            <td>{{ $row['product_name'] }}<br><small class="text-muted">{{ $row['product_code'] }}</small></td>
                            <td class="text-end">{{ rtrim(rtrim(number_format($row['returned_non_serial'], 3, ',', '.'), '0'), ',') }}</td>
                            <td class="text-end">{{ rtrim(rtrim(number_format($row['sold_quantity'], 3, ',', '.'), '0'), ',') }}</td>
                            <td class="text-end">{{ rtrim(rtrim(number_format($row['acquired_quantity'], 3, ',', '.'), '0'), ',') }}</td>
                            <td class="text-end">{{ rtrim(rtrim(number_format($row['acquired_already_returned'], 3, ',', '.'), '0'), ',') }}</td>
                            <td>
                                {{ implode(', ', $row['owned_destinations']) }}
                                <br><small class="text-muted">Unit bukan milik: {{ $row['consignment_location'] }}</small>
                            </td>
                            <td style="min-width: 260px">
                                @if($row['status'] === 'APPLIED')
                                    <span class="badge bg-success">Diterapkan: {{ $row['declared_acquired'] + 0 }}</span>
                                @elseif(! $row['feasible'])
                                    <span class="badge bg-danger">Tidak ada pembagian yang valid; hubungi admin.</span>
                                @elseif($declarationEditable)
                                    <form method="POST" action="{{ route('consignments.buyouts.returns.declare', $row['detail_id']) }}" class="d-flex gap-2 align-items-center">
                                        @csrf
                                        <input type="number" name="acquired_quantity" class="form-control form-control-sm" style="max-width: 110px"
                                               step="0.001" min="{{ $row['min'] }}" max="{{ $row['max'] }}" required
                                               value="{{ $row['declared_acquired'] ?? '' }}"
                                               aria-label="Unit milik perusahaan untuk {{ $row['product_name'] }}">
                                        <button type="submit" class="btn btn-sm btn-outline-primary">Simpan</button>
                                    </form>
                                    <small class="text-muted">Batas: {{ $row['min'] + 0 }} &ndash; {{ $row['max'] + 0 }}</small>
                                    @if($row['status'] === 'DECLARED')
                                        <br><small class="text-success">Dideklarasikan: {{ $row['declared_acquired'] + 0 }}</small>
                                    @endif
                                @else
                                    {{ $row['status'] === 'DECLARED' ? 'Dideklarasikan: ' . ($row['declared_acquired'] + 0) : 'Belum dideklarasikan' }}
                                @endif
                            </td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            </div>
        </div>
    </div>
@endif

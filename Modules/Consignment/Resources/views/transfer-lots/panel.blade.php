{{-- Consignment receipt-lot selection (consignment sources only). Saved separately; no stock effect. --}}
<section class="border border-info rounded p-3 mb-3" id="consignment-transfer-lots" aria-labelledby="consignment-lots-title">
    <h6 id="consignment-lots-title" class="mb-1">Lot Penerimaan Konsinyasi</h6>
    <p class="text-muted small mb-2">
        Pilih dari penerimaan konsinyasi mana barang non-seri dikirim. Jika hanya ada satu lot, lot tersebut diusulkan dan tetap harus dikonfirmasi.
        Supplier dan bisnis asal penerimaan tidak berubah karena transfer.
    </p>
    <form action="{{ route('consignments.transfer-lots.confirm', $transfer) }}" method="POST">
        @csrf
        @foreach($rows as $row)
            <div class="mb-3">
                <div class="font-weight-bold">
                    {{ $row['product_name'] }} · {{ $row['source'] }} → {{ $row['destination'] }} · {{ rtrim(rtrim(number_format($row['quantity'], 3, ',', '.'), '0'), ',') }} unit
                    @if($row['confirmed'])<span class="badge badge-success">Dikonfirmasi</span>
                    @elseif($row['sole_proposal'])<span class="badge badge-info">Diusulkan (satu lot)</span>
                    @else<span class="badge badge-warning">Perlu dipilih</span>@endif
                </div>
                @if($row['candidates'] === [])
                    <div class="text-danger small">Tidak ada lot penerimaan konsinyasi tersedia di lokasi sumber.</div>
                @else
                    <div class="table-responsive">
                        <table class="table table-sm mb-0">
                            <thead><tr><th>Penerimaan</th><th>Supplier</th><th class="text-right">Tersedia</th><th style="width: 10rem">Jumlah dikirim</th></tr></thead>
                            <tbody>
                            @foreach($row['candidates'] as $c)
                                <tr>
                                    <td>{{ $c['reference'] ?? ('#' . $c['receipt_id']) }}</td>
                                    <td>{{ $c['supplier'] }}</td>
                                    <td class="text-right">{{ $c['available'] }}
                                        @if($c['blockers'])<div class="text-danger small">{{ implode(' ', $c['blockers']) }}</div>@endif
                                    </td>
                                    <td>
                                        <input type="number" step="0.001" min="0" class="form-control form-control-sm"
                                               name="lots[{{ $row['row_id'] }}][{{ $c['receipt_id'] }}]"
                                               value="{{ old('lots.' . $row['row_id'] . '.' . $c['receipt_id'], $c['value']) }}"
                                               aria-label="Jumlah dari penerimaan {{ $c['reference'] ?? $c['receipt_id'] }}">
                                    </td>
                                </tr>
                            @endforeach
                            </tbody>
                        </table>
                    </div>
                @endif
            </div>
        @endforeach
        <button type="submit" class="btn btn-outline-info btn-sm">Konfirmasi Lot Konsinyasi</button>
    </form>
</section>

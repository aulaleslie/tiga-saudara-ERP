<div class="row mb-4">
    <div class="col-sm-4">
        <h6 class="mb-3">Informasi Transaksi:</h6>
        <div>Struk: <strong>{{ $detailView['transaction']['receipt_number'] ?: '-' }}</strong></div>
        <div>
            Tanggal:
            @if(! empty($detailView['transaction']['date']))
                {{ \Carbon\Carbon::parse($detailView['transaction']['date'])->format('d M Y H:i') }}
            @else
                -
            @endif
        </div>
        <div>Kode Transaksi: {{ $detailView['transaction']['transaction_code'] ?: '-' }}</div>
        <div>Pelanggan: {{ $detailView['transaction']['customer_name'] ?: 'Guest' }}</div>
    </div>
    <div class="col-sm-4">
        <h6 class="mb-3">Pembayaran Asli:</h6>
        @forelse($detailView['payments'] as $payment)
            <div>{{ $payment['method_name'] ?? '-' }}: {{ format_currency($payment['amount'] ?? 0) }}</div>
        @empty
            <div class="text-muted">Tidak ada data pembayaran.</div>
        @endforelse
        <div class="mt-2">Total: <strong>{{ format_currency($detailView['transaction']['grand_total'] ?? 0) }}</strong></div>
    </div>
    <div class="col-sm-4">
        <h6 class="mb-3">Informasi Tambahan:</h6>
        <div class="alert alert-info py-2 mb-3">
            <small><i class="fas fa-info-circle mr-1"></i> Halaman ini bersifat readonly untuk review retur. Semua keputusan resolusi ditampilkan sebagai badge status.</small>
        </div>
        <div class="small text-muted">Linked Sales Return: <strong>{{ count($detailView['linked_sale_returns']) }}</strong></div>
        <div class="small text-muted">Baris aksi retur: <strong>{{ $detailView['actionable_count'] }}</strong></div>
    </div>
</div>

<div class="mb-4">
    <h6 class="text-uppercase text-muted small mb-3">Linked Sales Returns</h6>
    @forelse($detailView['linked_sale_returns'] as $linkedSaleReturn)
        <div class="border rounded p-2 mb-2">
            <div class="fw-semibold">{{ $linkedSaleReturn['reference'] }}</div>
            <div class="small text-muted">{{ $linkedSaleReturn['sale_reference'] ?: '-' }}</div>
            <div class="small">Status: {{ $linkedSaleReturn['status'] ?: '-' }}</div>
            <div class="small">Lokasi: {{ $linkedSaleReturn['location_name'] ?: '-' }}</div>
        </div>
    @empty
        <div class="text-muted small">Belum ada Sales Return terhubung.</div>
    @endforelse
</div>

@forelse($detailView['groups'] as $group)
    <div class="card mb-3 border">
        <div class="card-header py-2 bg-light d-flex justify-content-between align-items-center">
            <div>
                <strong>{{ $group['product_name'] }}</strong>
                <small class="text-muted ml-2">{{ $group['product_code'] }}</small>
                @if($group['is_bundle'])
                    <span class="badge badge-info ml-2">Bundle</span>
                @endif
                @if($group['is_tracked'])
                    <span class="badge badge-primary ml-2">Tracked Serial</span>
                @endif
            </div>
            <div class="text-muted small">
                Harga Satuan: <strong>{{ format_currency($group['unit_price']) }}</strong>
            </div>
        </div>
        <div class="card-body p-0">
            @if(! empty($group['serial_rows']))
                <table class="table table-sm mb-0">
                    <thead class="thead-light">
                        <tr>
                            <th style="width: 220px;">Serial Diretur</th>
                            <th class="text-center" style="width: 160px;">Resolusi</th>
                            <th style="width: 220px;">Serial Pengganti</th>
                            <th>Eksekusi Sales Return</th>
                            <th class="text-right" style="width: 160px;">Nominal Tunai</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($group['serial_rows'] as $row)
                            <tr>
                                <td>
                                    <code class="font-weight-bold">{{ $row['returned_serial'] ?: '-' }}</code>
                                </td>
                                <td class="text-center">
                                    <span class="badge {{ $row['resolution_badge'] }}">{{ $row['resolution_label'] }}</span>
                                </td>
                                <td>
                                    @if($row['replacement_serial'])
                                        <span class="badge badge-info p-2">{{ $row['replacement_serial'] }}</span>
                                    @else
                                        <span class="text-muted small">-</span>
                                    @endif
                                </td>
                                <td>
                                    <div class="fw-semibold">{{ $row['execution_label'] }}</div>
                                    @if($row['execution_meta'])
                                        <div class="small text-muted">{{ $row['execution_meta'] }}</div>
                                    @endif
                                    @if(! empty($row['execution_mode']))
                                        <div class="small text-muted">Mode: {{ $row['execution_mode'] === 'serial_inventory_replacement' ? 'Penggantian Serial' : 'Catatan Non-Serial' }}</div>
                                    @endif
                                    @if(! empty($row['replacement_reason']))
                                        <div class="small text-muted">Alasan: {{ $row['replacement_reason'] }}</div>
                                    @endif
                                </td>
                                <td class="text-right">
                                    @if($row['cash_amount'] > 0)
                                        <span class="text-success font-weight-bold">{{ format_currency($row['cash_amount']) }}</span>
                                    @else
                                        <span class="text-muted small">-</span>
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            @elseif(! empty($group['non_serial_rows']))
                <table class="table table-sm mb-0">
                    <thead class="thead-light">
                        <tr>
                            <th style="width: 180px;">Resolusi</th>
                            <th class="text-center" style="width: 140px;">Qty Diretur</th>
                            <th>Eksekusi Sales Return</th>
                            <th class="text-right" style="width: 180px;">Nominal Tunai</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($group['non_serial_rows'] as $row)
                            <tr>
                                <td>
                                    <span class="badge {{ $row['resolution_badge'] }}">{{ $row['resolution_label'] }}</span>
                                </td>
                                <td class="text-center font-weight-bold">{{ rtrim(rtrim(number_format($row['quantity'], 4, '.', ''), '0'), '.') }}</td>
                                <td>
                                    <div class="fw-semibold">{{ $row['execution_label'] }}</div>
                                    @if($row['execution_meta'])
                                        <div class="small text-muted">{{ $row['execution_meta'] }}</div>
                                    @endif
                                    @if(! empty($row['execution_mode']))
                                        <div class="small text-muted">Mode: {{ $row['execution_mode'] === 'serial_inventory_replacement' ? 'Penggantian Serial' : 'Catatan Non-Serial' }}</div>
                                    @endif
                                    @if(! empty($row['replacement_reason']))
                                        <div class="small text-muted">Alasan: {{ $row['replacement_reason'] }}</div>
                                    @endif
                                </td>
                                <td class="text-right">
                                    @if($row['cash_amount'] > 0)
                                        <span class="text-success font-weight-bold">{{ format_currency($row['cash_amount']) }}</span>
                                    @else
                                        <span class="text-muted small">-</span>
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            @endif

            @if(! empty($group['bundle_trace']))
                <div class="border-top p-3 bg-light">
                    <div class="text-muted small font-weight-bold mb-2">Komponen Trace</div>
                    @foreach($group['bundle_trace'] as $bundleTrace)
                        <div class="small text-muted d-flex justify-content-between mb-1">
                            <span>
                                {{ $bundleTrace['product_name'] }}
                                @if($bundleTrace['product_code'])
                                    <span class="ml-1">({{ $bundleTrace['product_code'] }})</span>
                                @endif
                            </span>
                            <span>{{ rtrim(rtrim(number_format($bundleTrace['total_component_quantity'], 4, '.', ''), '0'), '.') }} unit</span>
                        </div>
                    @endforeach
                </div>
            @endif
        </div>
    </div>
@empty
    <div class="text-muted text-center py-3">Tidak ada baris retur.</div>
@endforelse

<div class="card bg-light mb-4">
    <div class="card-body py-3">
        <div class="row">
            <div class="col-sm-6">
                <div class="text-muted">Item aktif: <strong>{{ $detailView['actionable_count'] }}</strong></div>
            </div>
            <div class="col-sm-6 text-right">
                <div>Total Retur Tunai: <strong class="text-success">{{ format_currency($detailView['total_cash_return']) }}</strong></div>
            </div>
        </div>
    </div>
</div>

<details class="border rounded p-3 mb-3 bg-light">
    <summary class="fw-semibold">Audit &amp; Sumber Teknis</summary>
    <dl class="row mb-0 small mt-3">
        <dt class="col-sm-3 text-muted">Snapshot Hash</dt>
        <dd class="col-sm-9 text-break">{{ $detailView['audit']['snapshot_hash'] ?: '-' }}</dd>
        <dt class="col-sm-3 text-muted">Receipt</dt>
        <dd class="col-sm-9">{{ $detailView['audit']['receipt_number'] ?: '-' }}</dd>
        <dt class="col-sm-3 text-muted">Transaksi</dt>
        <dd class="col-sm-9">{{ $detailView['audit']['transaction_code'] ?: '-' }}</dd>
        <dt class="col-sm-3 text-muted">POS Checkout</dt>
        <dd class="col-sm-9">#{{ $detailView['audit']['pos_checkout_id'] ?: '-' }}</dd>
        <dt class="col-sm-3 text-muted">POS Transaction</dt>
        <dd class="col-sm-9">#{{ $detailView['audit']['pos_transaction_id'] ?: '-' }}</dd>
        <dt class="col-sm-3 text-muted">Source Setting</dt>
        <dd class="col-sm-9">{{ $detailView['audit']['source_setting_ids'] ? implode(', ', $detailView['audit']['source_setting_ids']) : '-' }}</dd>
        <dt class="col-sm-3 text-muted">Source Location</dt>
        <dd class="col-sm-9">{{ $detailView['audit']['source_location_ids'] ? implode(', ', $detailView['audit']['source_location_ids']) : '-' }}</dd>
        <dt class="col-sm-3 text-muted">Sale ID</dt>
        <dd class="col-sm-9">{{ $detailView['audit']['sale_ids'] ? implode(', ', $detailView['audit']['sale_ids']) : '-' }}</dd>
        <dt class="col-sm-3 text-muted">Sale Detail ID</dt>
        <dd class="col-sm-9">{{ $detailView['audit']['sale_detail_ids'] ? implode(', ', $detailView['audit']['sale_detail_ids']) : '-' }}</dd>
    </dl>
</details>

@if($detailView['snapshot_context']['available'])
    <details class="border rounded p-3 bg-white">
        <summary class="fw-semibold">Snapshot Transaksi Asli</summary>
        <div class="mt-3">
            @foreach($detailView['snapshot_context']['groups'] as $snapshotGroup)
                <div class="card mb-3 border">
                    <div class="card-header py-2 bg-light d-flex justify-content-between align-items-center">
                        <div>
                            <strong>{{ $snapshotGroup['product_name'] }}</strong>
                            <small class="text-muted ml-2">{{ $snapshotGroup['product_code'] }}</small>
                        </div>
                        <div>
                            @if($snapshotGroup['is_bundle'])
                                <span class="badge badge-info">Bundle</span>
                            @endif
                            @if($snapshotGroup['is_tracked'])
                                <span class="badge badge-primary">Tracked Serial</span>
                            @endif
                        </div>
                    </div>
                    <div class="card-body p-0">
                        <table class="table table-sm mb-0">
                            <thead class="thead-light">
                                <tr>
                                    <th>{{ $snapshotGroup['is_tracked'] ? 'Serial' : 'Status' }}</th>
                                    <th class="text-center">Qty Asli</th>
                                    <th class="text-center">Qty Diretur</th>
                                    <th class="text-center">Status</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach($snapshotGroup['rows'] as $snapshotRow)
                                    <tr>
                                        <td>
                                            @if($snapshotGroup['is_tracked'])
                                                <code class="font-weight-bold">{{ $snapshotRow['serial_label'] ?: '-' }}</code>
                                            @else
                                                <span class="text-muted">Ringkasan Baris</span>
                                            @endif
                                        </td>
                                        <td class="text-center">{{ rtrim(rtrim(number_format($snapshotRow['original_quantity'], 4, '.', ''), '0'), '.') }}</td>
                                        <td class="text-center">{{ rtrim(rtrim(number_format($snapshotRow['returned_quantity'], 4, '.', ''), '0'), '.') }}</td>
                                        <td class="text-center"><span class="badge {{ $snapshotRow['status_badge'] }}">{{ $snapshotRow['status_label'] }}</span></td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                </div>
            @endforeach
        </div>
    </details>
@endif
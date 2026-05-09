@extends('layouts.app')

@section('title', 'Preview Persetujuan Retur POS')

@section('breadcrumb')
    <ol class="breadcrumb border-0 m-0">
        <li class="breadcrumb-item"><a href="{{ route('home') }}">Home</a></li>
        <li class="breadcrumb-item"><a href="{{ route('pos.sessions.index') }}">POS</a></li>
        <li class="breadcrumb-item"><a href="{{ route('pos.returns.index') }}">Retur POS</a></li>
        <li class="breadcrumb-item"><a href="{{ route('pos.returns.show', $return) }}">{{ $return->reference }}</a></li>
        <li class="breadcrumb-item active">Preview Persetujuan</li>
    </ol>
@endsection

@section('content')
    <div class="container-fluid">
        <div class="d-flex flex-column flex-md-row justify-content-between align-items-md-center mb-3 gap-2">
            <div>
                <h1 class="h3 mb-1">Preview Persetujuan Retur POS</h1>
                <p class="text-muted mb-0">Preview ini bersifat baca-saja dan belum melakukan persetujuan final.</p>
            </div>
            <div class="d-flex gap-2">
                <a href="{{ route('pos.returns.show', $return) }}" class="btn btn-outline-secondary">
                    <i class="bi bi-arrow-left"></i> Kembali ke Detail
                </a>
            </div>
        </div>

        <div class="alert alert-warning" role="alert">
            <div class="fw-semibold mb-1">Persetujuan final belum tersedia</div>
            <div>Perubahan ini hanya membuka preview rencana persetujuan. Tidak ada tombol konfirmasi persetujuan pada halaman ini.</div>
        </div>

        @if(! empty($previewPlan['blockers']))
            <div class="alert alert-danger" role="alert">
                <div class="fw-semibold mb-2">Preview diblokir</div>
                <ul class="mb-0 ps-3">
                    @foreach($previewPlan['blockers'] as $blocker)
                        <li>{{ $blocker['message'] }}</li>
                    @endforeach
                </ul>
            </div>
        @endif

        @if(! empty($previewPlan['warnings']))
            <div class="alert alert-warning" role="alert">
                <div class="fw-semibold mb-2">Peringatan Preview</div>
                <ul class="mb-0 ps-3">
                    @foreach($previewPlan['warnings'] as $warning)
                        <li>{{ $warning['message'] }}</li>
                    @endforeach
                </ul>
            </div>
        @endif

        @if(! empty($previewPlan['info']))
            <div class="alert alert-info" role="alert">
                <div class="fw-semibold mb-2">Info Preview</div>
                <ul class="mb-0 ps-3">
                    @foreach($previewPlan['info'] as $item)
                        <li>{{ $item['message'] }}</li>
                    @endforeach
                </ul>
            </div>
        @endif

        <div class="row g-3 mb-3">
            <div class="col-lg-8">
                <div class="card h-100">
                    <div class="card-header">
                        <strong>Ringkasan Transaksi Sumber</strong>
                    </div>
                    <div class="card-body">
                        <div class="row g-3">
                            <div class="col-md-6">
                                <div class="text-muted small">Referensi Retur</div>
                                <div class="fw-semibold">{{ $return->reference }}</div>
                            </div>
                            <div class="col-md-6">
                                <div class="text-muted small">Receipt</div>
                                <div class="fw-semibold">{{ $detailView['transaction']['receipt_number'] ?: '-' }}</div>
                            </div>
                            <div class="col-md-6">
                                <div class="text-muted small">Kode Transaksi</div>
                                <div class="fw-semibold">{{ $detailView['transaction']['transaction_code'] ?: '-' }}</div>
                            </div>
                            <div class="col-md-6">
                                <div class="text-muted small">Pelanggan</div>
                                <div class="fw-semibold">{{ $detailView['transaction']['customer_name'] ?: 'Guest' }}</div>
                            </div>
                            <div class="col-md-6">
                                <div class="text-muted small">Tanggal Transaksi</div>
                                <div class="fw-semibold">{{ $detailView['transaction']['date'] ? \Carbon\Carbon::parse($detailView['transaction']['date'])->format('d M Y H:i') : '-' }}</div>
                            </div>
                            <div class="col-md-6">
                                <div class="text-muted small">Total Transaksi</div>
                                <div class="fw-semibold">{{ format_currency($detailView['transaction']['grand_total'] ?? 0) }}</div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            <div class="col-lg-4">
                <div class="card h-100">
                    <div class="card-header">
                        <strong>Status Lifecycle</strong>
                    </div>
                    <div class="card-body">
                        <div class="mb-3">
                            <div class="text-muted small">Status Retur</div>
                            <span class="badge badge-{{ $return->status_color }}">{{ $return->status_label }}</span>
                        </div>
                        <div class="mb-3">
                            <div class="text-muted small">Status Approval</div>
                            <span class="badge badge-warning text-dark">{{ ucfirst((string) $return->approval_status) }}</span>
                        </div>
                        <div>
                            <div class="text-muted small">Baris Aksi Retur</div>
                            <div class="fw-semibold">{{ $detailView['actionable_count'] ?? 0 }} baris</div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div class="card mb-3">
            <div class="card-header d-flex justify-content-between align-items-center">
                <strong>Pembayaran Asli</strong>
                <span class="text-muted small">Bersumber dari snapshot retur POS</span>
            </div>
            <div class="card-body">
                @if(empty($detailView['payments']))
                    <p class="text-muted mb-0">Belum ada data pembayaran pada snapshot sumber.</p>
                @else
                    <div class="table-responsive">
                        <table class="table table-sm table-bordered mb-0">
                            <thead>
                                <tr>
                                    <th>Metode</th>
                                    <th class="text-end">Jumlah</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach($detailView['payments'] as $payment)
                                    <tr>
                                        <td>{{ data_get($payment, 'method_name', '-') }}</td>
                                        <td class="text-end">{{ format_currency((float) data_get($payment, 'amount', 0)) }}</td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @endif
            </div>
        </div>

        <div class="card">
            <div class="card-header d-flex justify-content-between align-items-center">
                <strong>Ringkasan Retur POS</strong>
                <span class="text-muted small">Permukaan readonly dari retur yang diajukan</span>
            </div>
            <div class="card-body">
                @include('pos::returns.partials.readonly-detail', ['detailView' => $detailView])
            </div>
        </div>

        <div class="card mt-3">
            <div class="card-header d-flex justify-content-between align-items-center">
                <strong>Planned Sales Return Targets</strong>
                <span class="text-muted small">Dikelompokkan per sale sumber dan owner/lokasi</span>
            </div>
            <div class="card-body">
                @forelse($previewPlan['groups'] as $group)
                    <div class="border rounded p-3 mb-3">
                        <div class="d-flex flex-column flex-lg-row justify-content-between align-items-lg-start mb-3 gap-2">
                            <div>
                                <div class="text-muted small">Source Sale</div>
                                <div class="fw-semibold">{{ $group['source_sale']['reference'] ?: ('Sale #' . $group['source_sale']['id']) }}</div>
                                <div class="small text-muted">Status: {{ $group['source_sale']['status'] ?: '-' }}</div>
                            </div>
                            <div class="text-lg-end">
                                <div class="text-muted small">Owner / Lokasi</div>
                                <div class="fw-semibold">{{ $group['source_owner']['name'] ?: '-' }}</div>
                                <div class="small text-muted">{{ $group['source_location']['name'] ?: '-' }}</div>
                            </div>
                        </div>

                        <div class="row g-3 mb-3">
                            <div class="col-md-3">
                                <div class="text-muted small">Tax Context</div>
                                <div class="fw-semibold">{{ $group['tax_context']['tax_name'] ?: 'NON TAX' }}</div>
                            </div>
                            <div class="col-md-3">
                                <div class="text-muted small">Planned Total</div>
                                <div class="fw-semibold">{{ format_currency($group['planned_header']['total_amount'] ?? 0) }}</div>
                            </div>
                            <div class="col-md-3">
                                <div class="text-muted small">Cash Return</div>
                                <div class="fw-semibold">{{ format_currency($group['planned_header']['cash_return_total'] ?? 0) }}</div>
                            </div>
                            <div class="col-md-3">
                                <div class="text-muted small">Linked Sales Returns</div>
                                <div class="fw-semibold">{{ ! empty($group['linked_sale_return_references']) ? implode(', ', $group['linked_sale_return_references']) : '-' }}</div>
                            </div>
                        </div>

                        <div class="table-responsive">
                            <table class="table table-sm table-bordered align-middle mb-0">
                                <thead>
                                    <tr>
                                        <th>Produk</th>
                                        <th>Resolusi</th>
                                        <th class="text-end">Qty</th>
                                        <th class="text-end">Nominal</th>
                                        <th>Dispatch</th>
                                        <th>Owner / Lokasi</th>
                                        <th>Tax</th>
                                        <th>SN Retur</th>
                                        <th>SN Pengganti</th>
                                        <th>Intent</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    @foreach($group['planned_details'] as $detail)
                                        <tr>
                                            <td>
                                                <div class="fw-semibold">{{ $detail['product_name'] }}</div>
                                                <div class="small text-muted">{{ $detail['product_code'] ?: '-' }}</div>
                                                @if(! empty($detail['bundle_trace']))
                                                    <div class="small text-muted">Komponen bundle: {{ count($detail['bundle_trace']) }}</div>
                                                @endif
                                            </td>
                                            <td>{{ $detail['resolution_label'] }}</td>
                                            <td class="text-end">{{ rtrim(rtrim(number_format($detail['quantity'], 4, '.', ''), '0'), '.') }}</td>
                                            <td class="text-end">
                                                <div>{{ format_currency($detail['amount']) }}</div>
                                                @if($detail['cash_return_amount'] > 0)
                                                    <div class="small text-success">Cash: {{ format_currency($detail['cash_return_amount']) }}</div>
                                                @endif
                                            </td>
                                            <td>
                                                <div>#{{ $detail['dispatch_detail_id'] ?: '-' }}</div>
                                                <div class="small text-muted">{{ $detail['dispatch_resolution'] ?: '-' }}</div>
                                            </td>
                                            <td>
                                                <div>{{ $detail['source_setting_name'] ?: '-' }}</div>
                                                <div class="small text-muted">{{ $detail['source_location_name'] ?: '-' }}</div>
                                            </td>
                                            <td>{{ $detail['tax_name'] ?: 'NON TAX' }}</td>
                                            <td>{{ $detail['returned_serial'] ?: '-' }}</td>
                                            <td>{{ $detail['replacement_serial'] ?: '-' }}</td>
                                            <td>
                                                <div class="small">Stok: {{ $detail['stock_movement_intent'] }}</div>
                                                <div class="small text-muted">Serial: {{ $detail['serial_movement_intent'] }}</div>
                                            </td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                    </div>
                @empty
                    <p class="text-muted mb-0">Belum ada target Sales Return yang dapat direncanakan.</p>
                @endforelse

                <div class="row g-3">
                    <div class="col-md-6">
                        <div class="border rounded p-3 h-100">
                            <div class="text-muted small">Total Potensi Retur Tunai</div>
                            <div class="fw-semibold fs-5">{{ format_currency($detailView['total_cash_return'] ?? 0) }}</div>
                        </div>
                    </div>
                    <div class="col-md-6">
                        <div class="border rounded p-3 h-100">
                            <div class="text-muted small">Linked Sales Return Saat Ini</div>
                            <div class="fw-semibold fs-5">{{ count($detailView['linked_sale_returns'] ?? []) }}</div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
@endsection
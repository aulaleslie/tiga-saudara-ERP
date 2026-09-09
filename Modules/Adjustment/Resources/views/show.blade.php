@extends('layouts.app')

@section('title', 'Adjustment Details')

@push('page_css')
    @livewireStyles
@endpush

@section('breadcrumb')
    <ol class="breadcrumb border-0 m-0">
        <li class="breadcrumb-item"><a href="{{ route('home') }}">Beranda</a></li>
        <li class="breadcrumb-item"><a href="{{ route('adjustments.index') }}">Penyesuaian</a></li>
        <li class="breadcrumb-item active">Rincian</li>
    </ol>
@endsection

@section('content')
    <div class="container-fluid">
        <div class="row mb-3">
            <div class="col-12">
                <a href="{{ route('adjustments.index') }}" class="btn btn-secondary">
                    Kembali
                </a>

                @php
                    $user = auth()->user();
                    $canApproveAny = $user?->can('adjustments.approval');
                    $canApproveBreakage = $user?->can('adjustments.breakage.approval');
                    $canRejectGeneric = $user?->can('adjustments.reject');
                    $canEdit = $user?->can('adjustments.edit');

                    // Normalisasi
                    $statusValue = $adjustment->status instanceof \Modules\Adjustment\Entities\AdjustmentStatus
                        ? $adjustment->status->value
                        : $adjustment->status;
                    $status      = is_string($statusValue) ? strtolower(trim($statusValue)) : $statusValue;
                    $type        = is_string($adjustment->type)   ? strtolower(trim($adjustment->type))   : $adjustment->type;
                    $isBreakage  = $type === 'breakage';
                    $isVersioned = $adjustment->isNormalVersioned();

                    if ($isVersioned) {
                        // Redesigned Stock Opname lifecycle: draft -> waiting_approval -> approved/rejected.
                        // Submit only accepts DRAFT; a rejected document must first be
                        // edited (which returns it to draft) before it can be resubmitted.
                        $isDraft = $status === 'draft';
                        $isWaitingApproval = $status === 'waiting_approval';

                        $showSubmit  = $isDraft && $canEdit;
                        $showApprove = $isWaitingApproval && $canApproveAny;
                        $showReject  = $isWaitingApproval && $canApproveAny;
                    } else {
                        // Legacy/breakage lifecycle: single 'pending' status gate.
                        $isPending = $status === 'pending';

                        $showSubmit  = false;
                        $showApprove = $isPending && ($isBreakage ? ($canApproveBreakage || $canApproveAny) : $canApproveAny);
                        $showReject  = $isPending && ($isBreakage ? ($canApproveBreakage || $canApproveAny || $canRejectGeneric) : ($canApproveAny || $canRejectGeneric));
                    }
                @endphp

                @if($showSubmit)
                    <form action="{{ route('adjustments.submit', $adjustment) }}" method="POST" class="d-inline">
                        @csrf @method('PATCH')
                        <button type="submit" class="btn btn-primary">Ajukan Persetujuan</button>
                    </form>
                @endif

                @if($showApprove)
                    <form action="{{ route('adjustments.approve', $adjustment) }}" method="POST" class="d-inline">
                        @csrf @method('PATCH')
                        <button type="submit" class="btn btn-success">Setuju</button>
                    </form>
                @endif

                @if($showReject)
                    @if($isVersioned)
                        <form action="{{ route('adjustments.reject', $adjustment) }}" method="POST" class="d-inline"
                              onsubmit="return promptStockOpnameRejectionReason(this);">
                            @csrf @method('PATCH')
                            <input type="hidden" name="rejection_reason" class="js-rejection-reason">
                            <button type="submit" class="btn btn-danger">Tolak</button>
                        </form>
                        <script>
                            function promptStockOpnameRejectionReason(form) {
                                const reason = window.prompt('Masukkan alasan penolakan (wajib diisi):', '');
                                if (reason === null || reason.trim() === '') {
                                    alert('Alasan penolakan wajib diisi.');
                                    return false;
                                }
                                form.querySelector('.js-rejection-reason').value = reason.trim();
                                return true;
                            }
                        </script>
                    @else
                        <form action="{{ route('adjustments.reject', $adjustment) }}" method="POST" class="d-inline">
                            @csrf @method('PATCH')
                            <button type="submit" class="btn btn-danger">Tolak</button>
                        </form>
                    @endif
                @endif
            </div>
        </div>

        <!-- Header Table -->
        <div class="row">
            <div class="col-12">
                <div class="card">
                    <div class="card-body">
                        <div class="table-responsive">
                            <table class="table table-bordered">
                                <tr>
                                    <th>Tanggal</th>
                                    <td>{{ $adjustment->date }}</td>
                                    <th>Reference</th>
                                    <td>{{ $adjustment->reference }}</td>
                                </tr>
                                <tr>
                                    <th>Lokasi</th>
                                    <td>{{ $adjustment->location->name ?? '-' }}</td>
                                    <th>Jenis Penyesuaian</th>
                                    <td>{{ strtoupper($adjustment->type) }}</td>
                                </tr>
                            </table>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Details Table -->
        <div class="row mt-4">
            <div class="col-12">
                <div class="card">
                    <div class="card-header d-flex justify-content-between align-items-center">
                        <h5 class="mb-0">Rincian Perhitungan Fisik (Stock Opname)</h5>
                        @if($adjustment->isVersionedCountDraft())
                            <span class="badge badge-info">Draf Proposal v{{ $adjustment->count_draft['schema_version'] ?? 1 }}</span>
                        @endif
                    </div>
                    <div class="card-body">
                        @if($adjustment->isVersionedCountDraft())
                            <div class="alert alert-info py-2 mb-3">
                                <strong>Status Proposal:</strong> Dokumen ini merupakan proposal perhitungan fisik stok (stock opname) yang belum mempengaruhi inventaris fisik sampai disetujui.
                                @if(!empty($adjustment->count_draft['baseline_captured_at']))
                                    <div class="small text-muted mt-1">
                                        Waktu Snapshot Baseline: {{ \Carbon\Carbon::parse($adjustment->count_draft['baseline_captured_at'])->format('d M Y, H:i:s') }}
                                        | Perlakuan Pajak: <strong>{{ !empty($adjustment->count_draft['is_pkp']) ? 'PKP' : 'Non-PKP' }}</strong>
                                    </div>
                                @endif
                            </div>

                            <div class="table-responsive">
                                <table class="table table-bordered table-striped">
                                    <thead class="thead-light">
                                    <tr>
                                        <th>Nama Produk</th>
                                        <th>Kode</th>
                                        <th>Satuan</th>
                                        @can('adjustments.view-system-stock')
                                            <th class="text-center">Stok Sistem (Bagus / Rusak)</th>
                                        @endcan
                                        <th class="text-center">Hasil Hitung Fisik (Bagus / Rusak)</th>
                                        @can('adjustments.view-system-stock')
                                            <th class="text-center">Selisih Fisik (Bagus / Rusak)</th>
                                        @endcan
                                        <th>Daftar Nomor Seri Terhitung</th>
                                    </tr>
                                    </thead>
                                    <tbody>
                                    @foreach($adjustment->count_draft['rows'] ?? [] as $row)
                                        @php
                                            $baseGood = (int) ($row['baseline']['existing_good_total'] ?? 0);
                                            $baseBad = (int) ($row['baseline']['existing_bad_total'] ?? 0);
                                            $propGood = (int) ($row['good_count'] ?? 0);
                                            $propBad = (int) ($row['bad_count'] ?? 0);
                                            $diffGood = $propGood - $baseGood;
                                            $diffBad = $propBad - $baseBad;
                                        @endphp
                                        <tr>
                                            <td class="align-middle font-weight-bold">{{ $row['product_name'] }}</td>
                                            <td class="align-middle">{{ $row['product_code'] }}</td>
                                            <td class="align-middle">{{ $row['base_unit'] }}</td>
                                            @can('adjustments.view-system-stock')
                                                <td class="align-middle text-center">
                                                    <span class="badge badge-secondary">Bagus: {{ $baseGood }}</span>
                                                    <span class="badge badge-secondary">Rusak: {{ $baseBad }}</span>
                                                </td>
                                            @endcan
                                            <td class="align-middle text-center">
                                                <span class="badge badge-primary">Bagus: {{ $propGood }}</span>
                                                <span class="badge badge-warning">Rusak: {{ $propBad }}</span>
                                            </td>
                                            @can('adjustments.view-system-stock')
                                                <td class="align-middle text-center">
                                                    <span class="badge {{ $diffGood >= 0 ? 'badge-success' : 'badge-danger' }}">
                                                        Bagus: {{ $diffGood > 0 ? "+{$diffGood}" : $diffGood }}
                                                    </span>
                                                    <span class="badge {{ $diffBad >= 0 ? 'badge-warning' : 'badge-danger' }}">
                                                        Rusak: {{ $diffBad > 0 ? "+{$diffBad}" : $diffBad }}
                                                    </span>
                                                </td>
                                            @endcan
                                            <td class="align-middle">
                                                @if(!empty($row['serials']))
                                                    <div style="max-height: 120px; overflow-y: auto;">
                                                        <ul class="mb-0 ps-3 pl-3 small">
                                                            @foreach($row['serials'] as $s)
                                                                <li>
                                                                    <code>{{ $s['serial_number'] }}</code>
                                                                    <span class="badge badge-sm {{ ($s['condition'] ?? 'good') === 'good' ? 'badge-success' : 'badge-warning' }}">
                                                                        {{ ($s['condition'] ?? 'good') === 'good' ? 'BAGUS' : 'RUSAK' }}
                                                                    </span>
                                                                    @if(!empty($s['source_location_name']))
                                                                        <span class="text-muted">({{ $s['source_location_name'] }})</span>
                                                                    @else
                                                                        <span class="text-muted">(Nomor Seri Baru)</span>
                                                                    @endif
                                                                </li>
                                                            @endforeach
                                                        </ul>
                                                    </div>
                                                @else
                                                    <span class="text-muted">Non-Serial</span>
                                                @endif
                                            </td>
                                        </tr>
                                    @endforeach
                                    </tbody>
                                </table>
                            </div>
                        @else
                            <div class="table-responsive">
                                <table class="table table-bordered">
                                    <thead class="thead-light">
                                    <tr>
                                        <th>Nama Produk</th>
                                        <th>Kode Produk</th>
                                        @can('adjustments.view-system-stock')
                                            <th>Stok</th>
                                        @endcan
                                        <th>Kuantitas Terhitung</th>
                                        <th>Serial Numbers</th>
                                    </tr>
                                    </thead>
                                    <tbody>
                                    @foreach($adjustment->adjustedProducts as $adjustedProduct)
                                        <tr>
                                            <td>{{ $adjustedProduct->product->product_name }}</td>
                                            <td>{{ $adjustedProduct->product->product_code }}</td>
                                            @can('adjustments.view-system-stock')
                                                <td class="text-center">
                                                    <span class="badge badge-info">
                                                        {{ $adjustedProduct->stock_info['quantity'] }} {{ $adjustedProduct->stock_info['unit'] }}
                                                    </span>
                                                    <span class="d-inline-block"
                                                          data-toggle="tooltip"
                                                          data-placement="top"
                                                          title="Stok Pajak: {{ $adjustedProduct->stock_info['quantity_tax'] }} {{ $adjustedProduct->stock_info['unit'] }} | Stok Non-Pajak: {{ $adjustedProduct->stock_info['quantity_non_tax'] }} {{ $adjustedProduct->stock_info['unit'] }} | Rusak Pajak: {{ $adjustedProduct->stock_info['broken_quantity_tax'] }} {{ $adjustedProduct->stock_info['unit'] }} | Rusak Non-Pajak: {{ $adjustedProduct->stock_info['broken_quantity_non_tax'] }} {{ $adjustedProduct->stock_info['unit'] }}">
                                                        <i class="bi bi-info-circle text-primary" style="cursor: pointer;"></i>
                                                    </span>
                                                </td>
                                            @endcan
                                            <td class="text-center">{{ $adjustedProduct->quantity }}</td>
                                            <td>
                                                @if(!empty($adjustedProduct->serialNumbers))
                                                    <ol class="mb-0 ps-3">
                                                        @foreach($adjustedProduct->serialNumbers as $serial)
                                                            <li>{{ $serial['serial_number'] }} - {{ $serial['tax_label'] }}</li>
                                                        @endforeach
                                                    </ol>
                                                @else
                                                    <span class="text-muted">N/A</span>
                                                @endif
                                            </td>
                                        </tr>
                                    @endforeach
                                    </tbody>
                                </table>
                            </div>
                        @endif
                    </div>
                </div>
            </div>
        </div>
    </div>
@endsection

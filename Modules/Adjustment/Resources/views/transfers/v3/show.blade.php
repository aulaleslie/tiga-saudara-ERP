@php
    use Modules\Adjustment\Entities\Transfer;
    use Modules\Adjustment\Http\Controllers\TransferV3Controller;

    $statusLabels = [
        Transfer::STATUS_DRAFT      => ['Draf', 'secondary'],
        Transfer::STATUS_PENDING    => ['Menunggu Persetujuan', 'warning'],
        Transfer::STATUS_REJECTED   => ['Ditolak', 'danger'],
        Transfer::STATUS_DISPATCHED => ['Dikirim', 'info'],
        Transfer::STATUS_COMPLETED  => ['Selesai', 'success'],
        Transfer::STATUS_CANCELLED  => ['Pengiriman Dibatalkan', 'dark'],
    ];
    [$statusLabel, $statusClass] = $statusLabels[$transfer->status] ?? [$transfer->status, 'secondary'];
@endphp
@extends('layouts.app')

@section('title', 'Detail Transfer Stok')

@section('breadcrumb')
    <ol class="breadcrumb border-0 m-0">
        <li class="breadcrumb-item"><a href="{{ route('home') }}">Beranda</a></li>
        <li class="breadcrumb-item"><a href="{{ route('transfers.index') }}">Transfer Stok</a></li>
        <li class="breadcrumb-item active">Detail Transfer Stok</li>
    </ol>
@endsection

@section('content')
    <div class="container-fluid">
        @include('utils.alerts')
        <div class="card">
            <div class="card-body">
                <h5 class="card-title">Informasi Transfer Stok</h5>
                <table class="table table-bordered mb-4">
                    <tr>
                        <th style="width: 25%">Nomor Dokumen</th>
                        <td>{{ $transfer->document_number ?? '-' }}</td>
                    </tr>
                    <tr>
                        <th>Tanggal Dokumen</th>
                        <td>{{ optional($transfer->created_at)->format('Y-m-d H:i:s') ?? '-' }}</td>
                    </tr>
                    <tr>
                        <th>Kondisi Barang</th>
                        <td>
                            @if($transfer->stock_condition === Transfer::CONDITION_BREAKAGE)
                                <span class="badge badge-warning">Barang Rusak</span>
                            @else
                                <span class="badge badge-success">Barang Baik</span>
                            @endif
                        </td>
                    </tr>
                    <tr>
                        <th>Status</th>
                        <td><span class="badge badge-{{ $statusClass }}" id="transfer-status">{{ $statusLabel }}</span></td>
                    </tr>
                    <tr>
                        <th>Dibuat Oleh</th>
                        <td>{{ $transfer->createdBy->name ?? '-' }}</td>
                    </tr>
                    @if($transfer->approvedBy)
                        <tr>
                            <th>Disetujui &amp; Dikirim Oleh</th>
                            <td>{{ $transfer->approvedBy->name }} <small class="text-muted">{{ optional($transfer->approved_at)->format('Y-m-d H:i:s') }}</small></td>
                        </tr>
                    @endif
                    @if($transfer->receivedBy)
                        <tr>
                            <th>Diterima Oleh</th>
                            <td>{{ $transfer->receivedBy->name }} <small class="text-muted">{{ optional($transfer->received_at)->format('Y-m-d H:i:s') }}</small></td>
                        </tr>
                    @endif
                    @if($transfer->cancelledBy)
                        <tr>
                            <th>Pengiriman Dibatalkan Oleh</th>
                            <td>
                                {{ $transfer->cancelledBy->name }} <small class="text-muted">{{ optional($transfer->cancelled_at)->format('Y-m-d H:i:s') }}</small><br>
                                <small>Alasan: {{ $transfer->cancellation_reason }}</small>
                            </td>
                        </tr>
                    @endif
                </table>

                <h5 class="card-title">Daftar Barang</h5>
                <table class="table table-bordered table-sm">
                    <thead>
                    <tr>
                        <th class="text-center" style="width: 5%">#</th>
                        <th>Nama</th>
                        <th>Kode</th>
                        <th class="text-center">Jumlah</th>
                        <th>Nomor Seri</th>
                    </tr>
                    </thead>
                    <tbody>
                    @foreach($transfer->products as $i => $item)
                        <tr>
                            <td class="text-center">{{ $i + 1 }}</td>
                            <td>{{ $item->product->product_name ?? '-' }}</td>
                            <td>{{ $item->product->product_code ?? '-' }}</td>
                            <td class="text-center">{{ (int) $item->quantity }}</td>
                            <td>
                                @forelse($item->serial_numbers ?? [] as $serial)
                                    <span class="badge badge-light border">{{ $serial['serial_number'] ?? '-' }}</span>
                                @empty
                                    -
                                @endforelse
                            </td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>

                @if($routes->isNotEmpty())
                    {{-- Executed routes: approval authority only. --}}
                    <h5 class="card-title mt-4">Rute Pengiriman</h5>
                    <table class="table table-bordered table-sm" id="v3-executed-routes">
                        <thead>
                        <tr>
                            <th>Produk</th>
                            <th>Dari</th>
                            <th>Ke</th>
                            <th class="text-center">Jumlah</th>
                            <th class="text-center">Lintas Bisnis</th>
                        </tr>
                        </thead>
                        <tbody>
                        @foreach($routes as $route)
                            <tr>
                                <td>{{ $route->product->product_name ?? '-' }}</td>
                                <td>{{ $route->sourceLocation->name ?? '-' }} <small class="text-muted">{{ $route->sourceLocation->setting->company_name ?? '' }}</small></td>
                                <td>{{ $route->destinationLocation->name ?? '-' }} <small class="text-muted">{{ $route->destinationLocation->setting->company_name ?? '' }}</small></td>
                                <td class="text-center">{{ $route->quantity }}</td>
                                <td class="text-center">{!! $route->cross_business ? '<span class="badge badge-warning">Ya</span>' : 'Tidak' !!}</td>
                            </tr>
                        @endforeach
                        </tbody>
                    </table>
                @endif

                <div class="mt-4">
                    @if($transfer->status === Transfer::STATUS_DRAFT)
                        @can('stockTransfers.edit')
                            <a href="{{ route('transfers.edit', $transfer) }}" class="btn btn-info">Ubah</a>
                            <form action="{{ route('transfers.v3.submit', $transfer) }}" method="POST" class="d-inline">
                                @csrf
                                <button class="btn btn-primary">Ajukan Persetujuan</button>
                            </form>
                        @endcan
                    @endif

                    @if($transfer->status === Transfer::STATUS_PENDING)
                        @can('stockTransfers.approval')
                            <a href="{{ route('transfers.v3.approval', $transfer) }}" class="btn btn-success">Atur Alokasi &amp; Persetujuan</a>
                        @endcan
                        @can('stockTransfers.edit')
                            <a href="{{ route('transfers.edit', $transfer) }}" class="btn btn-info">Ubah</a>
                        @endcan
                    @endif

                    @if($transfer->status === Transfer::STATUS_REJECTED)
                        @can('stockTransfers.edit')
                            <form action="{{ route('transfers.v3.acknowledge-rejection', $transfer) }}" method="POST" class="d-inline">
                                @csrf
                                <button class="btn btn-secondary">Kembalikan ke Draf untuk Revisi</button>
                            </form>
                        @endcan
                    @endif

                    @if($transfer->status === Transfer::STATUS_DISPATCHED)
                        @can('stockTransfers.receive')
                            <button type="button" class="btn btn-success" data-toggle="modal" data-target="#v3-receive-modal">Terima Barang</button>
                        @endcan
                        @can('stockTransfers.cancel-dispatch')
                            <button type="button" class="btn btn-outline-danger" data-toggle="modal" data-target="#v3-cancel-modal">Batalkan Pengiriman</button>
                        @endcan
                    @endif

                    <a href="{{ route('transfers.index') }}" class="btn btn-secondary ml-2">Kembali</a>
                </div>
            </div>
        </div>

        @if($history !== null)
            <div class="card" id="v3-history">
                <div class="card-body">
                    <h5 class="card-title">Riwayat Transfer</h5>
                    <table class="table table-sm table-striped">
                        <thead>
                        <tr>
                            <th>Waktu</th>
                            <th>Aksi</th>
                            <th>Oleh</th>
                            <th>Keterangan</th>
                        </tr>
                        </thead>
                        <tbody>
                        @foreach($history as $event)
                            <tr>
                                <td>{{ $event['at'] }}</td>
                                <td>{{ $event['label'] }}</td>
                                <td>{{ $event['actor'] }}</td>
                                <td>
                                    {{ $event['reason'] }}
                                    @if(!empty($event['evidence']['request_revision_number']))
                                        <small class="text-muted d-block">Revisi pengajuan #{{ $event['evidence']['request_revision_number'] }}</small>
                                    @endif
                                    @if(!empty($event['evidence']['configuration_revision']))
                                        <small class="text-muted d-block">Revisi alokasi #{{ $event['evidence']['configuration_revision'] }}</small>
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                        </tbody>
                    </table>
                </div>
            </div>
        @endif
    </div>

    @if($transfer->status === Transfer::STATUS_DISPATCHED)
        @can('stockTransfers.receive')
            <div class="modal fade" id="v3-receive-modal" tabindex="-1" role="dialog" aria-labelledby="v3-receive-title" aria-hidden="true">
                <div class="modal-dialog" role="document">
                    <form class="modal-content" action="{{ route('transfers.v3.receive', $transfer) }}" method="POST">
                        @csrf
                        <input type="hidden" name="confirm" value="1">
                        <input type="hidden" name="operation_key" value="{{ $operationKey }}">
                        <div class="modal-header">
                            <h5 class="modal-title" id="v3-receive-title">Terima Barang</h5>
                        </div>
                        <div class="modal-body">
                            <p>{{ TransferV3Controller::RECEIPT_CONFIRMATION }}</p>
                        </div>
                        <div class="modal-footer">
                            <button type="button" class="btn btn-secondary" data-dismiss="modal">Batal</button>
                            <button type="submit" class="btn btn-success" onclick="this.disabled=true; this.form.submit();">Konfirmasi Penerimaan</button>
                        </div>
                    </form>
                </div>
            </div>
        @endcan

        @can('stockTransfers.cancel-dispatch')
            <div class="modal fade" id="v3-cancel-modal" tabindex="-1" role="dialog" aria-labelledby="v3-cancel-title" aria-hidden="true">
                <div class="modal-dialog" role="document">
                    <form class="modal-content" action="{{ route('transfers.v3.cancel-dispatch', $transfer) }}" method="POST">
                        @csrf
                        <input type="hidden" name="operation_key" value="{{ $operationKey }}">
                        <div class="modal-header">
                            <h5 class="modal-title" id="v3-cancel-title">Batalkan Pengiriman</h5>
                        </div>
                        <div class="modal-body">
                            <p>{{ TransferV3Controller::CANCELLATION_CONFIRMATION }}</p>
                            <div class="form-group">
                                <label for="v3-cancel-reason">Alasan Pembatalan <span class="text-danger">*</span></label>
                                <textarea id="v3-cancel-reason" name="reason" class="form-control" maxlength="255" required></textarea>
                            </div>
                            <div class="form-check">
                                <input class="form-check-input" type="checkbox" name="confirm" value="1" id="v3-cancel-confirm" required>
                                <label class="form-check-label" for="v3-cancel-confirm">Saya memastikan barang belum diserahkan atau telah dikembalikan ke lokasi asal.</label>
                            </div>
                        </div>
                        <div class="modal-footer">
                            <button type="button" class="btn btn-secondary" data-dismiss="modal">Batal</button>
                            <button type="submit" class="btn btn-danger">Batalkan Pengiriman</button>
                        </div>
                    </form>
                </div>
            </div>
        @endcan
    @endif
@endsection

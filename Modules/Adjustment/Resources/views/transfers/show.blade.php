@php use Modules\Adjustment\Entities\Transfer; @endphp
@extends('layouts.app')

@section('title', 'Detail Pemindahan Barang')

@section('breadcrumb')
    <ol class="breadcrumb border-0 m-0">
        <li class="breadcrumb-item"><a href="{{ route('home') }}">Beranda</a></li>
        <li class="breadcrumb-item"><a href="{{ route('transfers.index') }}">Pemindahan Barang</a></li>
        <li class="breadcrumb-item active">Detail Pemindahan Barang</li>
    </ol>
@endsection

@section('content')
    <div class="container-fluid">
        <div class="row">
            <div class="col-md-12">
                <div class="card">
                    <div class="card-body">
                        <h5 class="card-title">Informasi Pemindahan Stok</h5>
                        <table class="table table-bordered mb-4">
                            <tr>
                                <th>Tanggal Dokumen</th>
                                <td>{{ optional($transfer->created_at)->format('Y-m-d H:i:s') ?? '-' }}</td>
                            </tr>
                            <tr>
                                <th>Lokasi Asal</th>
                                <td>
                                    {{ $transfer->originLocation->name ?? '-' }}<br>
                                    <small>{{ $transfer->originLocation->setting->company_name ?? '-' }}</small>
                                </td>
                            </tr>
                            <tr>
                                <th>Lokasi Tujuan</th>
                                <td>
                                    {{ $transfer->destinationLocation->name ?? '-' }}<br>
                                    <small>{{ $transfer->destinationLocation->setting->company_name ?? '-' }}</small>
                                </td>
                            </tr>
                            <tr>
                                <th>Status Saat Ini</th>
                                <td>
                                    <span class="badge badge-info">{{ str_replace('_', ' ', $transfer->status) }}</span>
                                    @if($requiresReturn)
                                        <span class="badge badge-warning ml-2">Butuh Pengembalian</span>
                                    @endif
                                </td>
                            </tr>
                            <tr>
                                <th>Dibuat Oleh</th>
                                <td>{{ $transfer->createdBy->name ?? '-' }}</td>
                            </tr>
                            @if($transfer->approvedBy)
                                <tr>
                                    <th>Disetujui Oleh</th>
                                    <td>
                                        {{ $transfer->approvedBy->name }}<br>
                                        <small
                                            class="text-muted">{{ optional($transfer->approved_at)->format('Y-m-d H:i:s') }}</small>
                                    </td>
                                </tr>
                            @endif
                            @if($transfer->dispatchedBy)
                                <tr>
                                    <th>Dikirim Oleh</th>
                                    <td>
                                        {{ $transfer->dispatchedBy->name }}<br>
                                        <small
                                            class="text-muted">{{ optional($transfer->dispatched_at)->format('Y-m-d H:i:s') }}</small>
                                    </td>
                                </tr>
                            @endif
                            @if($transfer->receivedBy)
                                <tr>
                                    <th>Diterima Oleh</th>
                                    <td>
                                        {{ $transfer->receivedBy->name }}<br>
                                        <small
                                            class="text-muted">{{ optional($transfer->received_at)->format('Y-m-d H:i:s') }}</small>
                                    </td>
                                </tr>
                            @endif
                            @if($transfer->returnDispatchedBy)
                                <tr>
                                    <th>Dikirim Kembali Oleh</th>
                                    <td>
                                        {{ $transfer->returnDispatchedBy->name }}<br>
                                        <small
                                            class="text-muted">{{ optional($transfer->return_dispatched_at)->format('Y-m-d H:i:s') }}</small>
                                    </td>
                                </tr>
                            @endif
                            @if($transfer->returnReceivedBy)
                                <tr>
                                    <th>Diterima Kembali Oleh</th>
                                    <td>
                                        {{ $transfer->returnReceivedBy->name }}<br>
                                        <small
                                            class="text-muted">{{ optional($transfer->return_received_at)->format('Y-m-d H:i:s') }}</small>
                                    </td>
                                </tr>
                            @endif
                        </table>

                        <h5 class="card-title">Daftar Barang</h5>
                        <table class="table table-bordered">
                            <thead>
                            <tr>
                                <th>#</th>
                                <th>Nama</th>
                                <th>Kode</th>
                                <th class="text-center">Pajak</th>
                                <th class="text-center">Non Pajak</th>
                                <th class="text-center">Rusak Pajak</th>
                                <th class="text-center">Rusak Non Pajak</th>
                                <th class="text-center">Jumlah</th>
                            </tr>
                            </thead>
                            <tbody>
                            @foreach($transfer->products as $i => $item)
                                @php
                                    $qt  = $item->quantity_tax;
                                    $qn  = $item->quantity_non_tax;
                                    $bqt = $item->quantity_broken_tax;
                                    $bqn = $item->quantity_broken_non_tax;
                                    $total = $qt + $qn + $bqt + $bqn;
                                @endphp
                                <tr>
                                    <td>{{ $i + 1 }}</td>
                                    <td>{{ $item->product->product_name }}</td>
                                    <td>{{ $item->product->product_code }}</td>
                                    <td class="text-center">{{ $qt }}</td>
                                    <td class="text-center">{{ $qn }}</td>
                                    <td class="text-center">{{ $bqt }}</td>
                                    <td class="text-center">{{ $bqn }}</td>
                                    <td class="text-center">{{ $total }}</td>
                                </tr>
                            @endforeach
                            </tbody>
                        </table>

                        <div class="mt-4">
                            {{-- Approve/Reject: only ORIGIN on PENDING --}}
                            @if($transfer->status === Transfer::STATUS_PENDING && $isOrigin)
                                @canany(['stockTransfers.edit','stockTransfers.approval'])
                                    <form action="{{ route('transfers.approve', $transfer) }}" method="POST"
                                          class="d-inline">
                                        @csrf
                                        <button class="btn btn-success">Setujui</button>
                                    </form>
                                    <form action="{{ route('transfers.reject', $transfer) }}" method="POST"
                                          class="d-inline">
                                        @csrf
                                        <button class="btn btn-danger">Tolak</button>
                                    </form>
                                @endcanany

                                {{-- Dispatch: only ORIGIN on APPROVED --}}
                            @elseif($transfer->status === Transfer::STATUS_APPROVED && $isOrigin)
                                @can('stockTransfers.dispatch')
                                    <form action="{{ route('transfers.dispatch', $transfer) }}" method="POST"
                                          class="d-inline">
                                        @csrf
                                        <button class="btn btn-primary">Keluarkan</button>
                                    </form>
                                @endcan

                                {{-- Receive: only DESTINATION on DISPATCHED --}}
                            @elseif($transfer->status === Transfer::STATUS_DISPATCHED && $isDestination)
                                @can('stockTransfers.receive')
                                    <form action="{{ route('transfers.receive', $transfer) }}" method="POST"
                                          class="d-inline">
                                        @csrf
                                        <button class="btn btn-success">Terima</button>
                                    </form>
                                @endcan
                            @elseif($transfer->status === Transfer::STATUS_RECEIVED && $requiresReturn && $isDestination)
                                @can('stockTransfers.dispatch')
                                    <form action="{{ route('transfers.return-dispatch', $transfer) }}" method="POST"
                                          class="d-inline">
                                        @csrf
                                        <button class="btn btn-warning">Kirim Kembali</button>
                                    </form>
                                @endcan
                            @elseif($transfer->status === Transfer::STATUS_RETURN_DISPATCHED && $isOrigin)
                                @can('stockTransfers.receive')
                                    <form action="{{ route('transfers.return-receive', $transfer) }}" method="POST"
                                          class="d-inline">
                                        @csrf
                                        <button class="btn btn-success">Terima Kembali</button>
                                    </form>
                                @endcan
                            @endif

                            <a href="{{ route('transfers.index') }}" class="btn btn-secondary ml-2">
                                Kembali
                            </a>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
@endsection

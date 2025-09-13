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
                                <th>Tanggal:</th>
                                <td>{{ $transfer->created_at->format('Y-m-d H:i:s') }}</td>
                            </tr>
                            <tr>
                                <th>Lokasi Asal:</th>
                                <td>
                                    {{ $transfer->originLocation->name ?? '-' }}<br>
                                    <small>{{ $transfer->originLocation->setting->company_name ?? '-' }}</small>
                                </td>
                            </tr>
                            <tr>
                                <th>Lokasi Tujuan:</th>
                                <td>
                                    {{ $transfer->destinationLocation->name ?? '-' }}<br>
                                    <small>{{ $transfer->destinationLocation->setting->company_name ?? '-' }}</small>
                                </td>
                            </tr>
                            <tr>
                                <th>Status:</th>
                                <td>{{ strtoupper($transfer->status) }}</td>
                            </tr>
                            <tr>
                                <th>Dibuat Oleh:</th>
                                <td>{{ $transfer->createdBy->name ?? '-' }}</td>
                            </tr>
                            @if($transfer->approver)
                                <tr>
                                    <th>Disetujui Oleh:</th>
                                    <td>{{ $transfer->approver->name }}</td>
                                </tr>
                            @endif
                            @if($transfer->dispatcher)
                                <tr>
                                    <th>Dikeluarkan Oleh:</th>
                                    <td>{{ $transfer->dispatcher->name }}</td>
                                </tr>
                            @endif
                            @if($transfer->receiver)
                                <tr>
                                    <th>Diterima Oleh:</th>
                                    <td>{{ $transfer->receiver->name }}</td>
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
                            {{-- Approve/Reject: only DESTINATION on PENDING --}}
                            @if($transfer->status === 'PENDING' && $isDestination)
                                @canany(['stockTransfers.edit','stockTransfers.approval'])
                                    <form action="{{ route('transfers.approve', $transfer) }}" method="POST" class="d-inline">
                                        @csrf
                                        <button class="btn btn-success">Setujui</button>
                                    </form>
                                    <form action="{{ route('transfers.reject', $transfer) }}" method="POST" class="d-inline">
                                        @csrf
                                        <button class="btn btn-danger">Tolak</button>
                                    </form>
                                @endcanany

                                {{-- Dispatch: only ORIGIN on APPROVED --}}
                            @elseif($transfer->status === 'APPROVED' && $isOrigin)
                                @can('stockTransfers.dispatch')
                                    <form action="{{ route('transfers.dispatch', $transfer) }}" method="POST" class="d-inline">
                                        @csrf
                                        <button class="btn btn-primary">Keluarkan</button>
                                    </form>
                                @endcan

                                {{-- Receive: only DESTINATION on DISPATCHED --}}
                            @elseif($transfer->status === 'DISPATCHED' && $isDestination)
                                @can('stockTransfers.receive')
                                    <form action="{{ route('transfers.receive', $transfer) }}" method="POST" class="d-inline">
                                        @csrf
                                        <button class="btn btn-success">Terima</button>
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

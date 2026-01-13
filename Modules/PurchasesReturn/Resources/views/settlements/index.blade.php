@extends('layouts.app')

@section('title', 'Daftar Penyelesaian Retur Pembelian')

@section('breadcrumb')
    <ol class="breadcrumb border-0 m-0">
        <li class="breadcrumb-item"><a href="{{ route('home') }}">Beranda</a></li>
        <li class="breadcrumb-item"><a href="{{ route('purchase-returns.index') }}">Retur Pembelian</a></li>
        <li class="breadcrumb-item active">Penyelesaian</li>
    </ol>
@endsection

@section('content')
    <div class="container-fluid">
        <div class="row">
            <div class="col-12">
                <div class="card shadow-sm">
                    <div class="card-body">
                        <div class="table-responsive">
                            <table class="table table-bordered table-striped text-center align-middle">
                                <thead>
                                    <tr>
                                        <th>Ref Retur</th>
                                        <th>Pemasok</th>
                                        <th>Metode</th>
                                        <th>Tanggal Dibuat</th>
                                        <th>Status</th>
                                        <th>Aksi</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    @forelse($settlements as $settlement)
                                        <tr>
                                            <td>
                                                <a href="{{ route('purchase-returns.show', $settlement->purchase_return_id) }}" class="text-primary fw-bold">
                                                    {{ $settlement->purchaseReturn->reference }}
                                                </a>
                                            </td>
                                            <td>{{ $settlement->purchaseReturn->supplier->supplier_name }}</td>
                                            <td>{{ ucfirst($settlement->method) }}</td>
                                            <td>{{ $settlement->created_at->format('d M Y H:i') }}</td>
                                            <td>
                                                @if($settlement->status == 'pending')
                                                    <span class="badge bg-warning text-dark">Pending</span>
                                                @elseif($settlement->status == 'approved')
                                                    <span class="badge bg-primary">Approved</span>
                                                @elseif($settlement->status == 'rejected')
                                                    <span class="badge bg-danger">Rejected</span>
                                                @elseif($settlement->status == 'executing')
                                                    <span class="badge bg-info text-dark">Executing</span>
                                                @elseif($settlement->status == 'completed')
                                                    <span class="badge bg-success">Completed</span>
                                                @else
                                                    <span class="badge bg-secondary">{{ $settlement->status }}</span>
                                                @endif
                                            </td>
                                            <td>
                                                <a href="{{ route('purchase-returns.show', $settlement->purchase_return_id) }}" class="btn btn-sm btn-info text-white">
                                                    <i class="bi bi-eye"></i> Detail
                                                </a>
                                            </td>
                                        </tr>
                                    @empty
                                        <tr>
                                            <td colspan="6" class="text-center text-muted">Belum ada data penyelesaian retur.</td>
                                        </tr>
                                    @endforelse
                                </tbody>
                            </table>
                        </div>
                        <div class="mt-3">
                            {{ $settlements->links() }}
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
@endsection

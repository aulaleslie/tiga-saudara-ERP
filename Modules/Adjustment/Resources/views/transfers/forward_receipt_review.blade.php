@extends('layouts.app')

@section('title', 'Tinjauan Persetujuan Penerimaan')

@section('breadcrumb')
    <ol class="breadcrumb border-0 m-0">
        <li class="breadcrumb-item"><a href="{{ route('home') }}">Home</a></li>
        <li class="breadcrumb-item"><a href="{{ route('transfers.index') }}">Transfer Stok</a></li>
        <li class="breadcrumb-item"><a href="{{ route('transfers.show', $transfer->id) }}">{{ $transfer->document_number ?? ('#' . $transfer->id) }}</a></li>
        <li class="breadcrumb-item active">Tinjauan Penerimaan</li>
    </ol>
@endsection

@section('content')
    <div class="container-fluid">
        <div class="row">
            <div class="col-12">
                <div class="card">
                    <div class="card-header d-flex justify-content-between align-items-center">
                        <div>
                            <h4 class="card-title mb-0">Tinjauan Persetujuan Penerimaan (V2 Forward Receipt)</h4>
                            <small class="text-muted">Dokumen: {{ $transfer->document_number ?? ('#' . $transfer->id) }} | Revisi Gerakan: #{{ $movement->revision }}</small>
                        </div>
                        <div>
                            <span class="badge badge-warning p-2">{{ $movement->status }}</span>
                        </div>
                    </div>
                    <div class="card-body">
                        @if($projection['matches'])
                            <div class="alert alert-success" role="alert">
                                <h5 class="alert-heading">✓ Penerimaan Fisik Cocok</h5>
                                <p class="mb-0">Hasil perhitungan penerimaan fisik sesuai dengan manifest pengiriman yang telah disetujui.</p>
                            </div>
                        @else
                            <div class="alert alert-danger" role="alert">
                                <h5 class="alert-heading">⚠ Terdapat Ketidaksesuaian Penerimaan</h5>
                                <p class="mb-0">Hasil perhitungan fisik tidak sesuai dengan manifest pengiriman.</p>
                            </div>
                        @endif

                        @if($canViewSystemStock && !empty($projection['details']))
                            <h5 class="mt-4 mb-3">Detail Perbandingan Item Diterima</h5>
                            <div class="table-responsive">
                                <table class="table table-bordered table-striped">
                                    <thead class="bg-light">
                                        <tr>
                                            <th>Produk</th>
                                            <th>Kode</th>
                                            <th class="text-center">Dikirim (Manifest)</th>
                                            <th class="text-center">Diterima Fisik</th>
                                            <th class="text-center">Selisih</th>
                                            <th class="text-center">Status</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        @foreach($projection['details'] as $detail)
                                            <tr>
                                                 <td>{{ $detail['product_name'] }}</td>
                                                 <td>{{ $detail['product_code'] }}</td>
                                                 <td class="text-center">{{ $detail['expected_quantity'] }}</td>
                                                 <td class="text-center">{{ $detail['counted_quantity'] }}</td>
                                                 <td class="text-center {{ (float)$detail['difference_quantity'] != 0 ? 'text-danger font-weight-bold' : '' }}">
                                                     {{ $detail['difference_quantity'] }}
                                                 </td>
                                                 <td class="text-center">
                                                     @if($detail['status'] === 'MATCH')
                                                         <span class="badge badge-success">Cocok</span>
                                                     @else
                                                         <span class="badge badge-danger">{{ $detail['status'] }}</span>
                                                     @endif
                                                 </td>
                                             </tr>
                                         @endforeach
                                    </tbody>
                                </table>
                            </div>
                        @endif

                        <div class="d-flex justify-content-between align-items-center mt-4 pt-3 border-top">
                            <a href="{{ route('transfers.show', $transfer->id) }}" class="btn btn-secondary">
                                <i class="bi bi-arrow-left mr-1"></i> Kembali ke Detail Transfer
                            </a>
                            <div class="d-flex gap-2">
                                @if($movement->status === \Modules\Adjustment\Entities\TransferMovement::STATUS_PENDING)
                                    <form action="{{ route('transfers.movements.receipt.reject', ['transfer' => $transfer->id, 'movement' => $movement->id]) }}" method="POST" class="d-inline mr-2" onsubmit="return promptRejectReason(this);">
                                        @csrf
                                        <input type="hidden" name="reason" class="reject-reason" value="">
                                        <button type="submit" class="btn btn-danger">
                                            <i class="bi bi-x-circle mr-1"></i> Tolak Penerimaan
                                        </button>
                                    </form>
                                    @if($projection['matches'])
                                        <form action="{{ route('transfers.movements.receipt.approve', ['transfer' => $transfer->id, 'movement' => $movement->id]) }}" method="POST" class="d-inline">
                                            @csrf
                                            <button type="submit" class="btn btn-success">
                                                <i class="bi bi-check-circle mr-1"></i> Setujui Penerimaan & Tambah Stok
                                            </button>
                                        </form>
                                    @endif
                                @endif
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
@endsection

@push('scripts')
<script>
function promptRejectReason(form) {
    var reason = prompt("Masukkan alasan penolakan penerimaan:");
    if (!reason || reason.trim() === "") {
        alert("Alasan penolakan wajib diisi.");
        return false;
    }
    form.querySelector('.reject-reason').value = reason;
    return true;
}
</script>
@endpush

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
                                <th>Nomor Dokumen</th>
                                <td>{{ $transfer->document_number ?? '-' }}</td>
                            </tr>
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
                                    @if($transfer->status === Transfer::STATUS_RETURN_RECEIVED && $isOrigin && $requiresReturn)
                                        <span class="badge badge-success">Barang Sudah Dikembalikan</span>
                                    @else
                                        <span class="badge badge-info">{{ str_replace('_', ' ', $transfer->status) }}</span>
                                        @if($requiresReturn && $transfer->status !== Transfer::STATUS_RETURN_RECEIVED)
                                            <span class="badge badge-warning ml-2">Butuh Pengembalian</span>
                                        @endif
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
                        <table class="table table-bordered table-sm text-sm">
                            <thead>
                            <tr>
                                <th rowspan="2" class="align-middle text-center">#</th>
                                <th rowspan="2" class="align-middle">Nama</th>
                                <th rowspan="2" class="align-middle">Kode</th>
                                <th colspan="4" class="text-center bg-light">Rencana (Diajukan)</th>
                                <th colspan="4" class="text-center bg-info text-white">Aktual Dikirim</th>
                                <th colspan="2" class="text-center bg-warning">Wajib Retur (Pajak)</th>
                                <th colspan="2" class="text-center bg-secondary text-white">Retur Dikirim</th>
                                <th colspan="2" class="text-center bg-success text-white">Retur Diterima</th>
                            </tr>
                            <tr>
                                <th class="text-center bg-light" title="Pajak">P</th>
                                <th class="text-center bg-light" title="Non Pajak">NP</th>
                                <th class="text-center bg-light" title="Rusak Pajak">RP</th>
                                <th class="text-center bg-light" title="Rusak Non Pajak">RNP</th>
                                
                                <th class="text-center bg-info text-white" title="Pajak">P</th>
                                <th class="text-center bg-info text-white" title="Non Pajak">NP</th>
                                <th class="text-center bg-info text-white" title="Rusak Pajak">RP</th>
                                <th class="text-center bg-info text-white" title="Rusak Non Pajak">RNP</th>
                                
                                <th class="text-center bg-warning" title="Pajak">P</th>
                                <th class="text-center bg-warning" title="Rusak Pajak">RP</th>

                                <th class="text-center bg-secondary text-white" title="Pajak">P</th>
                                <th class="text-center bg-secondary text-white" title="Rusak Pajak">RP</th>

                                <th class="text-center bg-success text-white" title="Pajak">P</th>
                                <th class="text-center bg-success text-white" title="Rusak Pajak">RP</th>
                            </tr>
                            </thead>
                            <tbody>
                            @foreach($transfer->products as $i => $item)
                                @php
                                    $ob = $item->returnObligation;
                                @endphp
                                <tr>
                                    <td class="text-center">{{ $i + 1 }}</td>
                                    <td>{{ $item->product->product_name }}</td>
                                    <td>{{ $item->product->product_code }}</td>
                                    
                                    <td class="text-center bg-light">{{ $item->quantity_tax }}</td>
                                    <td class="text-center bg-light">{{ $item->quantity_non_tax }}</td>
                                    <td class="text-center bg-light">{{ $item->quantity_broken_tax }}</td>
                                    <td class="text-center bg-light">{{ $item->quantity_broken_non_tax }}</td>
                                    
                                    <td class="text-center bg-info text-white">{{ $item->dispatched_quantity_tax ?? '-' }}</td>
                                    <td class="text-center bg-info text-white">{{ $item->dispatched_quantity_non_tax ?? '-' }}</td>
                                    <td class="text-center bg-info text-white">{{ $item->dispatched_quantity_broken_tax ?? '-' }}</td>
                                    <td class="text-center bg-info text-white">{{ $item->dispatched_quantity_broken_non_tax ?? '-' }}</td>
                                    
                                    <td class="text-center bg-warning">{{ $ob ? $ob->required_quantity_tax : '-' }}</td>
                                    <td class="text-center bg-warning">{{ $ob ? $ob->required_quantity_broken_tax : '-' }}</td>

                                    <td class="text-center bg-secondary text-white">{{ $ob ? $ob->return_dispatched_quantity_tax : '-' }}</td>
                                    <td class="text-center bg-secondary text-white">{{ $ob ? $ob->return_dispatched_quantity_broken_tax : '-' }}</td>

                                    <td class="text-center bg-success text-white">{{ $ob ? $ob->return_received_quantity_tax : '-' }}</td>
                                    <td class="text-center bg-success text-white">{{ $ob ? $ob->return_received_quantity_broken_tax : '-' }}</td>
                                </tr>
                                @if(!empty($item->dispatched_serial_numbers))
                                    <tr>
                                        <td colspan="3" class="text-right text-muted small"><em>Seri Aktual:</em></td>
                                        <td colspan="14" class="small">
                                            @foreach($item->dispatched_serial_numbers as $sn)
                                                <span class="badge badge-{{ !empty($sn['taxable']) || !empty($sn['tax_id']) ? 'warning' : 'secondary' }} {{ !empty($sn['is_broken']) ? 'border border-danger' : '' }} mr-1">{{ $sn['serial_number'] }}</span>
                                            @endforeach
                                        </td>
                                    </tr>
                                @elseif(!empty($item->serial_numbers))
                                    <tr>
                                        <td colspan="3" class="text-right text-muted small"><em>Seri Direncanakan:</em></td>
                                        <td colspan="14" class="small">
                                            @foreach($item->serial_numbers as $sn)
                                                <span class="badge badge-light border mr-1">{{ $sn['serial_number'] }}</span>
                                            @endforeach
                                        </td>
                                    </tr>
                                @endif
                            @endforeach
                            </tbody>
                        </table>

                        {{-- Drift confirmation panel: show when drift exception is in session --}}
                        @if(session()->has('drift_exception'))
                            @php
                                $drift = session('drift_exception');
                                $allocations = $drift['allocations'] ?? [];
                            @endphp
                            <div class="alert alert-warning mt-4" role="alert">
                                <h5 class="alert-heading">⚠️ Perubahan Alokasi Stok Terdeteksi</h5>
                                <p class="mb-0">{{ $drift['message'] ?? 'Alokasi stok berubah sejak persetujuan.' }}</p>
                                <small class="text-muted d-block mt-2">Harap tinjau perbedaan di bawah dan konfirmasi jika sudah sesuai.</small>
                            </div>

                            <table class="table table-sm table-bordered mt-3">
                                <thead class="bg-light">
                                    <tr>
                                        <th>Produk</th>
                                        <th class="text-center">Rencana (Pajak)</th>
                                        <th class="text-center">Aktual (Pajak)</th>
                                        <th class="text-center">Selisih</th>
                                        <th class="text-center">Rencana (Rusak Pajak)</th>
                                        <th class="text-center">Aktual (Rusak Pajak)</th>
                                        <th class="text-center">Selisih</th>
                                    </tr>
                                </thead>
                                <tbody>
                                @foreach($transfer->products as $product)
                                    @php
                                        $productAllocation = $allocations[$product->id] ?? null;
                                        if (!$productAllocation) continue;
                                        
                                        $alloc = $productAllocation['allocation'] ?? [];
                                        $plannedTax = (int) $product->quantity_tax;
                                        $actualTax = $alloc['tax'] ?? 0;
                                        $diffTax = $actualTax - $plannedTax;
                                        
                                        $plannedBrokenTax = (int) $product->quantity_broken_tax;
                                        $actualBrokenTax = $alloc['broken_tax'] ?? 0;
                                        $diffBrokenTax = $actualBrokenTax - $plannedBrokenTax;
                                    @endphp
                                    <tr>
                                        <td>
                                            <strong>{{ $product->product->product_name }}</strong><br>
                                            <small class="text-muted">{{ $product->product->product_code }}</small>
                                        </td>
                                        <td class="text-center">{{ $plannedTax }}</td>
                                        <td class="text-center font-weight-bold">{{ $actualTax }}</td>
                                        <td class="text-center {{ $diffTax > 0 ? 'bg-danger text-white' : '' }}">
                                            {{ $diffTax > 0 ? '+' : '' }}{{ $diffTax }}
                                        </td>
                                        <td class="text-center">{{ $plannedBrokenTax }}</td>
                                        <td class="text-center font-weight-bold">{{ $actualBrokenTax }}</td>
                                        <td class="text-center {{ $diffBrokenTax > 0 ? 'bg-danger text-white' : '' }}">
                                            {{ $diffBrokenTax > 0 ? '+' : '' }}{{ $diffBrokenTax }}
                                        </td>
                                    </tr>
                                @endforeach
                                </tbody>
                            </table>

                            <div class="alert alert-info mt-3">
                                <p class="mb-0">
                                    <strong>Implikasi:</strong>
                                    Peningkatan eksposur pajak atau pengembalian wajib terdeteksi.
                                    Konfirmasi di bawah untuk melanjutkan pengiriman dengan alokasi aktual.
                                </p>
                            </div>
                        @endif

                        <div class="mt-4">
                            {{-- Approve/Reject: only ORIGIN on PENDING --}}
                            @if($transfer->status === Transfer::STATUS_PENDING && $isOrigin)
                                @can('stockTransfers.approval')
                                    <form action="{{ route('transfers.approve', $transfer) }}" method="POST"
                                          class="d-inline">
                                        @csrf
                                        <button class="btn btn-success">Setujui</button>
                                    </form>
                                    <form action="{{ route('transfers.reject', $transfer) }}" method="POST"
                                          class="d-inline" onsubmit="return promptReject(this);">
                                        @csrf
                                        <input type="hidden" name="reason" class="reject-reason" value="">
                                        <button class="btn btn-danger">Tolak</button>
                                    </form>
                                @endcan

                                {{-- Dispatch: only ORIGIN on APPROVED --}}
                            @elseif($transfer->status === Transfer::STATUS_APPROVED && $isOrigin)
                                @can('stockTransfers.dispatch')
                                    @if(session()->has('drift_exception'))
                                        {{-- Drift detected: show confirmation form --}}
                                        <form action="{{ route('transfers.dispatch', $transfer) }}" method="POST" class="d-inline">
                                            @csrf
                                            <input type="hidden" name="acknowledged_hash" value="{{ session('drift_exception.hash') }}">
                                            <button class="btn btn-danger">Konfirmasi Pengiriman (dengan perubahan alokasi)</button>
                                        </form>
                                        <button class="btn btn-secondary" onclick="location.reload()">Batalkan & Muat Ulang</button>
                                    @else
                                        {{-- Normal dispatch --}}
                                        <form action="{{ route('transfers.dispatch', $transfer) }}" method="POST" class="d-inline">
                                            @csrf
                                            <button class="btn btn-primary">Keluarkan</button>
                                        </form>
                                    @endif
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
                            @elseif($transfer->status === Transfer::STATUS_AWAITING_RETURN && $isDestination)
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

                            @if($transfer->status === Transfer::STATUS_REJECTED && $isOrigin)
                                @can('stockTransfers.edit')
                                    <form action="{{ route('transfers.acknowledge-rejection', $transfer) }}" method="POST"
                                          class="d-inline">
                                        @csrf
                                        <button class="btn btn-secondary">Akui Penolakan (Kembali ke Draf)</button>
                                    </form>
                                @endcan
                            @endif

                            @if(in_array($transfer->status, [Transfer::STATUS_REJECTED, Transfer::STATUS_COMPLETED, Transfer::STATUS_RETURN_RECEIVED]) && $isOrigin)
                                @can('stockTransfers.archive')
                                    <form action="{{ route('transfers.archive', $transfer) }}" method="POST"
                                          class="d-inline" onsubmit="return promptArchive(this);">
                                        @csrf
                                        <input type="hidden" name="reason" class="archive-reason" value="">
                                        <button class="btn btn-dark">Arsipkan</button>
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

@push('scripts')
<script>
    function promptReject(form) {
        var reason = prompt("Masukkan alasan penolakan:");
        if (reason === null || reason.trim() === "") {
            alert("Alasan penolakan wajib diisi.");
            return false;
        }
        form.querySelector('.reject-reason').value = reason;
        return true;
    }

    function promptArchive(form) {
        var reason = prompt("Masukkan alasan pengarsipan:");
        if (reason === null || reason.trim() === "") {
            alert("Alasan pengarsipan wajib diisi.");
            return false;
        }
        form.querySelector('.archive-reason').value = reason;
        return true;
    }
</script>
@endpush

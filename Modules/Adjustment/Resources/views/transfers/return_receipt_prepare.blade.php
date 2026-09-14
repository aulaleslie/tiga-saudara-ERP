@extends('layouts.app')

@section('title', 'Persiapan Penerimaan Retur Transfer Stok')

@section('breadcrumb')
    <ol class="breadcrumb border-0 m-0">
        <li class="breadcrumb-item"><a href="{{ route('home') }}">Home</a></li>
        <li class="breadcrumb-item"><a href="{{ route('transfers.index') }}">Transfer Stok</a></li>
        <li class="breadcrumb-item"><a href="{{ route('transfers.show', $transfer->id) }}">{{ $transfer->document_number ?? ('#' . $transfer->id) }}</a></li>
        <li class="breadcrumb-item active">Persiapan Penerimaan Retur</li>
    </ol>
@endsection

@section('content')
    <div class="container-fluid" id="return-receipt-app">
        <div class="row">
            <div class="col-12">
                <div class="card">
                    <div class="card-header d-flex justify-content-between align-items-center">
                        <div>
                            <h4 class="card-title mb-0">Penghitungan Penerimaan Retur Fisik (V2 Return Receipt)</h4>
                            <small class="text-muted">Dokumen: {{ $transfer->document_number ?? ('#' . $transfer->id) }} | Batch: {{ $movement->return_batch_id }} | Kondisi: {{ $movement->stock_condition }} | Revisi: #{{ $movement->revision }}</small>
                        </div>
                        <div>
                            <span class="badge badge-info p-2" id="movement-status-badge">{{ $movement->status }}</span>
                        </div>
                    </div>
                    <div class="card-body">
                        {{-- Scan Section --}}
                        <div class="row mb-4">
                            <div class="col-md-8">
                                <div class="input-group">
                                    <input type="text" id="scan-input" class="form-control form-control-lg" placeholder="Scan Barcode / Serial Number / Kode Produk..." autocomplete="off" autofocus>
                                    <div class="input-group-append">
                                        <button class="btn btn-primary" type="button" id="btn-scan">
                                            <i class="bi bi-upc-scan mr-1"></i> Scan
                                        </button>
                                    </div>
                                </div>
                                <div id="scan-feedback" class="mt-2"></div>
                            </div>
                            <div class="col-md-4 text-right">
                                <button type="button" id="btn-confirm-empty" class="btn btn-outline-warning mr-2">
                                    <i class="bi bi-exclamation-circle mr-1"></i> Konfirmasi Tidak Ada Barang
                                </button>
                                <form action="{{ route('transfers.movements.return-receipt.cancel', ['transfer' => $transfer->id, 'movement' => $movement->id]) }}" method="POST" class="d-inline" onsubmit="return confirm('Batalkan draft penerimaan retur ini?');">
                                    @csrf
                                    <button type="submit" class="btn btn-outline-danger">
                                        <i class="bi bi-trash mr-1"></i> Batalkan Draft
                                    </button>
                                </form>
                            </div>
                        </div>

                        {{-- Empty Confirmation Notice --}}
                        <div id="empty-confirmation-alert" class="alert alert-warning {{ $movement->empty_count_confirmed ? '' : 'd-none' }}">
                            <i class="bi bi-check2-circle mr-1"></i>
                            <strong>Telah dikonfirmasi:</strong> Tidak ada barang yang diterima secara fisik pada batch retur ini.
                        </div>

                        {{-- Line Table --}}
                        <div class="table-responsive">
                            <table class="table table-bordered table-striped" id="lines-table">
                                <thead class="bg-light">
                                    <tr>
                                        <th>Produk Diterima</th>
                                        <th>Kode</th>
                                        <th class="text-center" style="width: 220px;">Jumlah Diterima</th>
                                        <th class="text-center">Nomor Seri Terobservasi</th>
                                        <th class="text-center" style="width: 140px;">Aksi</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    @forelse($movement->lines as $line)
                                        <tr data-line-id="{{ $line->id }}" data-product-id="{{ $line->product_id }}" data-is-serialized="{{ $line->product?->serial_number_required ? '1' : '0' }}">
                                            <td>
                                                <strong>{{ $line->product?->product_name }}</strong>
                                                @if($line->product?->serial_number_required)
                                                    <span class="badge badge-secondary ml-1">Serial</span>
                                                @endif
                                            </td>
                                            <td>{{ $line->product?->product_code }}</td>
                                            <td class="text-center">
                                                @if($line->product?->serial_number_required)
                                                    <input type="text" class="form-control text-center font-weight-bold line-qty-input" readonly value="{{ (int)$line->quantity }}">
                                                @else
                                                    <div class="input-group">
                                                        <input type="number" step="1" min="0" class="form-control text-center line-qty-input" value="{{ (int)$line->quantity }}">
                                                        <div class="input-group-append">
                                                            <button class="btn btn-outline-secondary btn-save-qty" type="button" title="Simpan Jumlah">
                                                                <i class="bi bi-check"></i>
                                                            </button>
                                                        </div>
                                                    </div>
                                                @endif
                                            </td>
                                            <td>
                                                @if($line->product?->serial_number_required)
                                                    <div class="serials-list">
                                                        @forelse($line->serials as $s)
                                                            <span class="badge badge-light border p-1 m-1">{{ $s->serial_number }}</span>
                                                        @empty
                                                            <span class="text-muted small">Belum ada nomor seri di-scan</span>
                                                        @endforelse
                                                    </div>
                                                @else
                                                    <span class="text-muted small">Non-serialized</span>
                                                @endif
                                            </td>
                                            <td class="text-center">
                                                <button type="button" class="btn btn-sm btn-outline-danger btn-remove-line" title="Hapus Observasi">
                                                    <i class="bi bi-trash"></i>
                                                </button>
                                            </td>
                                        </tr>
                                    @empty
                                        <tr id="empty-row">
                                            <td colspan="5" class="text-center text-muted py-4">
                                                Belum ada barang yang di-scan atau dicatat. Mulai dengan men-scan barcode produk atau nomor seri.
                                            </td>
                                        </tr>
                                    @endforelse
                                </tbody>
                            </table>
                        </div>

                        {{-- Action Footer --}}
                        <div class="d-flex justify-content-between align-items-center mt-4 pt-3 border-top">
                            <a href="{{ route('transfers.show', $transfer->id) }}" class="btn btn-secondary">
                                <i class="bi bi-arrow-left mr-1"></i> Kembali ke Detail Transfer
                            </a>
                            <div>
                                <form action="{{ route('transfers.movements.return-receipt.submit', ['transfer' => $transfer->id, 'movement' => $movement->id]) }}" method="POST" class="d-inline" id="submit-receipt-form">
                                    @csrf
                                    <input type="hidden" name="lock_version" id="submit-lock-version" value="{{ $movement->lock_version }}">
                                    <button type="submit" class="btn btn-success btn-lg">
                                        <i class="bi bi-send-check mr-1"></i> Ajukan Penerimaan Retur
                                    </button>
                                </form>
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
document.addEventListener('DOMContentLoaded', function () {
    var transferId = {{ $transfer->id }};
    var movementId = {{ $movement->id }};
    var lockVersion = {{ $movement->lock_version }};

    var scanInput = document.getElementById('scan-input');
    var btnScan = document.getElementById('btn-scan');
    var btnConfirmEmpty = document.getElementById('btn-confirm-empty');
    var scanFeedback = document.getElementById('scan-feedback');
    var submitLockVersion = document.getElementById('submit-lock-version');
    var emptyConfirmationAlert = document.getElementById('empty-confirmation-alert');

    function showFeedback(message, type) {
        scanFeedback.innerHTML = '<div class="alert alert-' + type + ' alert-dismissible fade show mb-0" role="alert">' +
            message +
            '<button type="button" class="close" data-dismiss="alert" aria-label="Close"><span aria-hidden="true">&times;</span></button>' +
            '</div>';
    }

    function doScan() {
        var q = scanInput.value.trim();
        if (!q) return;

        fetch('{{ route("transfers.movements.return-receipt.scan", ["transfer" => $transfer->id, "movement" => $movement->id]) }}', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': '{{ csrf_token() }}',
                'Accept': 'application/json'
            },
            body: JSON.stringify({
                query: q,
                lock_version: lockVersion
            })
        })
        .then(function(res) { return res.json(); })
        .then(function(data) {
            scanInput.value = '';
            scanInput.focus();

            if (data.status === 'resolved') {
                showFeedback('Observasi berhasil dicatat.', 'success');
                window.location.reload();
            } else if (data.status === 'ambiguous') {
                showFeedback('Barcode ambigu. Ditemukan beberapa produk cocok.', 'warning');
            } else {
                showFeedback(data.message || 'Barang tidak ditemukan.', 'danger');
            }
        })
        .catch(function(err) {
            showFeedback('Terjadi kesalahan jaringan.', 'danger');
        });
    }

    if (btnScan) {
        btnScan.addEventListener('click', doScan);
    }

    if (scanInput) {
        scanInput.addEventListener('keydown', function (e) {
            if (e.key === 'Enter') {
                e.preventDefault();
                doScan();
            }
        });
    }

    if (btnConfirmEmpty) {
        btnConfirmEmpty.addEventListener('click', function () {
            if (!confirm('Konfirmasi bahwa benar-benar tidak ada barang fisik yang diterima pada batch ini?')) {
                return;
            }

            fetch('{{ route("transfers.movements.return-receipt.confirm-empty", ["transfer" => $transfer->id, "movement" => $movement->id]) }}', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': '{{ csrf_token() }}',
                    'Accept': 'application/json'
                },
                body: JSON.stringify({
                    lock_version: lockVersion
                })
            })
            .then(function(res) { return res.json(); })
            .then(function(data) {
                if (data.status === 'success') {
                    showFeedback('Penerimaan kosong berhasil dikonfirmasi.', 'info');
                    window.location.reload();
                } else {
                    showFeedback(data.message || 'Gagal mengonfirmasi penerimaan kosong.', 'danger');
                }
            })
            .catch(function() {
                showFeedback('Terjadi kesalahan jaringan.', 'danger');
            });
        });
    }

    // Line quantity save
    document.querySelectorAll('.btn-save-qty').forEach(function(btn) {
        btn.addEventListener('click', function() {
            var row = btn.closest('tr');
            var productId = row.getAttribute('data-product-id');
            var qtyInput = row.querySelector('.line-qty-input');
            var qty = qtyInput.value;

            fetch('{{ route("transfers.movements.return-receipt.set-quantity", ["transfer" => $transfer->id, "movement" => $movement->id]) }}', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': '{{ csrf_token() }}',
                    'Accept': 'application/json'
                },
                body: JSON.stringify({
                    product_id: productId,
                    quantity: qty,
                    count_confirmed: true,
                    lock_version: lockVersion
                })
            })
            .then(function(res) { return res.json(); })
            .then(function(data) {
                if (data.status === 'success') {
                    showFeedback('Jumlah berhasil disimpan.', 'success');
                    window.location.reload();
                } else {
                    showFeedback(data.message || 'Gagal menyimpan jumlah.', 'danger');
                }
            })
            .catch(function() {
                showFeedback('Terjadi kesalahan jaringan.', 'danger');
            });
        });
    });

    // Remove line
    document.querySelectorAll('.btn-remove-line').forEach(function(btn) {
        btn.addEventListener('click', function() {
            if (!confirm('Hapus observasi produk ini dari draf penerimaan retur?')) {
                return;
            }

            var row = btn.closest('tr');
            var productId = row.getAttribute('data-product-id');

            fetch('{{ route("transfers.movements.return-receipt.set-quantity", ["transfer" => $transfer->id, "movement" => $movement->id]) }}', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': '{{ csrf_token() }}',
                    'Accept': 'application/json'
                },
                body: JSON.stringify({
                    product_id: productId,
                    quantity: 0,
                    count_confirmed: false,
                    lock_version: lockVersion
                })
            })
            .then(function(res) { return res.json(); })
            .then(function(data) {
                if (data.status === 'success') {
                    showFeedback('Observasi dihapus.', 'info');
                    window.location.reload();
                } else {
                    showFeedback(data.message || 'Gagal menghapus observasi.', 'danger');
                }
            })
            .catch(function() {
                showFeedback('Terjadi kesalahan jaringan.', 'danger');
            });
        });
    });
});
</script>
@endpush

@extends('layouts.app')

@section('title', 'Persiapan Penerimaan Transfer Stok')

@section('breadcrumb')
    <ol class="breadcrumb border-0 m-0">
        <li class="breadcrumb-item"><a href="{{ route('home') }}">Home</a></li>
        <li class="breadcrumb-item"><a href="{{ route('transfers.index') }}">Transfer Stok</a></li>
        <li class="breadcrumb-item"><a href="{{ route('transfers.show', $transfer->id) }}">{{ $transfer->document_number ?? ('#' . $transfer->id) }}</a></li>
        <li class="breadcrumb-item active">Persiapan Penerimaan</li>
    </ol>
@endsection

@section('content')
    <div class="container-fluid" id="receipt-app">
        <div class="row">
            <div class="col-12">
                <div class="card">
                    <div class="card-header d-flex justify-content-between align-items-center">
                        <div>
                            <h4 class="card-title mb-0">Penghitungan Penerimaan Fisik (V2 Forward Receipt)</h4>
                            <small class="text-muted">Dokumen: {{ $transfer->document_number ?? ('#' . $transfer->id) }} | Kondisi: {{ $movement->stock_condition }} | Revisi: #{{ $movement->revision }}</small>
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
                                <form action="{{ route('transfers.movements.receipt.cancel', ['transfer' => $transfer->id, 'movement' => $movement->id]) }}" method="POST" class="d-inline" onsubmit="return confirm('Batalkan draft penerimaan ini?');">
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
                            <strong>Telah dikonfirmasi:</strong> Tidak ada barang yang diterima secara fisik pada transfer ini.
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
                                                            <span class="badge badge-primary mr-1 mb-1 p-2 serial-badge">
                                                                {{ $s->serial_number }}
                                                            </span>
                                                        @empty
                                                            <span class="text-muted font-italic">Belum ada seri discan</span>
                                                        @endforelse
                                                    </div>
                                                @else
                                                    <span class="text-muted">-</span>
                                                @endif
                                            </td>
                                            <td class="text-center">
                                                <button type="button" class="btn btn-sm btn-outline-danger btn-zero-line" title="Kosongkan Baris">
                                                    <i class="bi bi-x"></i> Hapus
                                                </button>
                                            </td>
                                        </tr>
                                    @empty
                                        <tr id="empty-table-row">
                                            <td colspan="5" class="text-center text-muted py-4 font-italic">
                                                Belum ada produk yang discan. Silakan scan produk atau konfirmasi penerimaan kosong.
                                            </td>
                                        </tr>
                                    @endforelse
                                </tbody>
                            </table>
                        </div>

                        {{-- Footer Actions --}}
                        <div class="d-flex justify-content-between align-items-center mt-4 pt-3 border-top">
                            <a href="{{ route('transfers.show', $transfer->id) }}" class="btn btn-secondary">
                                <i class="bi bi-arrow-left mr-1"></i> Kembali ke Detail Transfer
                            </a>
                            <form action="{{ route('transfers.movements.receipt.submit', ['transfer' => $transfer->id, 'movement' => $movement->id]) }}" method="POST" id="submit-form">
                                @csrf
                                <input type="hidden" name="lock_version" id="submit-lock-version" value="{{ $movement->lock_version }}">
                                <button type="submit" class="btn btn-success btn-lg" id="btn-submit-movement">
                                    <i class="bi bi-check-circle mr-1"></i> Ajukan Penghitungan Penerimaan
                                </button>
                            </form>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
@endsection

@push('scripts')
<script>
document.addEventListener('DOMContentLoaded', function() {
    const scanInput = document.getElementById('scan-input');
    const btnScan = document.getElementById('btn-scan');
    const scanFeedback = document.getElementById('scan-feedback');
    const linesTable = document.getElementById('lines-table');
    const submitLockVersion = document.getElementById('submit-lock-version');
    const btnConfirmEmpty = document.getElementById('btn-confirm-empty');
    const emptyAlert = document.getElementById('empty-confirmation-alert');

    let currentLockVersion = {{ $movement->lock_version }};

    function setFeedback(message, type = 'info') {
        scanFeedback.innerHTML = `<div class="alert alert-${type} py-2 mb-0">${message}</div>`;
        if (type === 'success') {
            setTimeout(() => {
                scanFeedback.innerHTML = '';
            }, 3000);
        }
    }

    function doScan() {
        const query = scanInput.value.trim();
        if (!query) return;

        scanInput.disabled = true;
        btnScan.disabled = true;

        fetch("{{ route('transfers.movements.receipt.scan', ['transfer' => $transfer->id, 'movement' => $movement->id]) }}", {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': '{{ csrf_token() }}',
                'Accept': 'application/json'
            },
            body: JSON.stringify({
                query: query,
                lock_version: currentLockVersion
            })
        })
        .then(res => res.json())
        .then(data => {
            scanInput.disabled = false;
            btnScan.disabled = false;
            scanInput.value = '';
            scanInput.focus();

            if (data.status === 'resolved' || data.status === 'success') {
                setFeedback(data.message || 'Scan berhasil.', 'success');
                if (data.projection) {
                    renderProjection(data.projection);
                }
            } else if (data.status === 'rejected' || data.status === 'not_found' || data.status === 'error') {
                setFeedback(data.message || 'Item tidak ditemukan atau ditolak.', 'danger');
                if (data.projection) {
                    renderProjection(data.projection);
                }
            } else if (data.status === 'ambiguous') {
                setFeedback('Ditemukan beberapa produk cocok. Silakan scan barcode spesifik atau serial number.', 'warning');
            }
        })
        .catch(err => {
            scanInput.disabled = false;
            btnScan.disabled = false;
            setFeedback('Terjadi kesalahan jaringan atau server saat memproses scan.', 'danger');
        });
    }

    btnScan.addEventListener('click', doScan);
    scanInput.addEventListener('keypress', function(e) {
        if (e.key === 'Enter') {
            e.preventDefault();
            doScan();
        }
    });

    btnConfirmEmpty.addEventListener('click', function() {
        if (!confirm('Apakah Anda yakin tidak ada barang yang diterima secara fisik pada transfer ini?')) {
            return;
        }

        fetch("{{ route('transfers.movements.receipt.confirm-empty', ['transfer' => $transfer->id, 'movement' => $movement->id]) }}", {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': '{{ csrf_token() }}',
                'Accept': 'application/json'
            }
        })
        .then(res => res.json())
        .then(data => {
            if (data.status === 'success') {
                setFeedback('Konfirmasi penerimaan kosong berhasil disimpan.', 'success');
                if (data.projection) {
                    renderProjection(data.projection);
                }
            } else {
                setFeedback(data.message || 'Gagal mengonfirmasi.', 'danger');
            }
        })
        .catch(err => {
            setFeedback('Terjadi kesalahan saat memproses konfirmasi.', 'danger');
        });
    });

    linesTable.addEventListener('click', function(e) {
        const btnSave = e.target.closest('.btn-save-qty');
        const btnZero = e.target.closest('.btn-zero-line');

        if (btnSave) {
            const tr = btnSave.closest('tr');
            const productId = tr.getAttribute('data-product-id');
            const qtyInput = tr.querySelector('.line-qty-input');
            const quantity = parseInt(qtyInput.value) || 0;

            saveLineQuantity(productId, quantity, true);
        }

        if (btnZero) {
            const tr = btnZero.closest('tr');
            const productId = tr.getAttribute('data-product-id');
            saveLineQuantity(productId, 0, true);
        }
    });

    function saveLineQuantity(productId, quantity, confirmed) {
        fetch("{{ route('transfers.movements.receipt.set-quantity', ['transfer' => $transfer->id, 'movement' => $movement->id]) }}", {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': '{{ csrf_token() }}',
                'Accept': 'application/json'
            },
            body: JSON.stringify({
                product_id: productId,
                quantity: quantity,
                count_confirmed: confirmed,
                lock_version: currentLockVersion
            })
        })
        .then(res => res.json())
        .then(data => {
            if (data.status === 'success') {
                setFeedback('Jumlah berhasil disimpan.', 'success');
                if (data.projection) {
                    renderProjection(data.projection);
                }
            } else {
                setFeedback(data.message || 'Gagal menyimpan jumlah.', 'danger');
            }
        })
        .catch(err => {
            setFeedback('Terjadi kesalahan saat menyimpan data.', 'danger');
        });
    }

    function renderProjection(proj) {
        if (proj.lock_version) {
            currentLockVersion = proj.lock_version;
            submitLockVersion.value = proj.lock_version;
        }

        if (proj.empty_count_confirmed) {
            emptyAlert.classList.remove('d-none');
        } else {
            emptyAlert.classList.add('d-none');
        }

        const tbody = linesTable.querySelector('tbody');
        tbody.innerHTML = '';

        if (!proj.lines || proj.lines.length === 0) {
            tbody.innerHTML = `
                <tr id="empty-table-row">
                    <td colspan="5" class="text-center text-muted py-4 font-italic">
                        Belum ada produk yang discan. Silakan scan produk atau konfirmasi penerimaan kosong.
                    </td>
                </tr>
            `;
            return;
        }

        proj.lines.forEach(line => {
            let serialHtml = '<span class="text-muted">-</span>';
            if (line.is_serialized) {
                if (line.serials && line.serials.length > 0) {
                    serialHtml = '<div class="serials-list">' + line.serials.map(s => 
                        `<span class="badge badge-primary mr-1 mb-1 p-2 serial-badge">${escapeHtml(s.serial_number)}</span>`
                    ).join('') + '</div>';
                } else {
                    serialHtml = '<span class="text-muted font-italic">Belum ada seri discan</span>';
                }
            }

            let qtyHtml = '';
            if (line.is_serialized) {
                qtyHtml = `<input type="text" class="form-control text-center font-weight-bold line-qty-input" readonly value="${parseInt(line.quantity)}">`;
            } else {
                qtyHtml = `
                    <div class="input-group">
                        <input type="number" step="1" min="0" class="form-control text-center line-qty-input" value="${parseInt(line.quantity)}">
                        <div class="input-group-append">
                            <button class="btn btn-outline-secondary btn-save-qty" type="button" title="Simpan Jumlah">
                                <i class="bi bi-check"></i>
                            </button>
                        </div>
                    </div>
                `;
            }

            const tr = document.createElement('tr');
            tr.setAttribute('data-line-id', line.line_id);
            tr.setAttribute('data-product-id', line.product_id);
            tr.setAttribute('data-is-serialized', line.is_serialized ? '1' : '0');

            tr.innerHTML = `
                <td>
                    <strong>${escapeHtml(line.product_name)}</strong>
                    ${line.is_serialized ? '<span class="badge badge-secondary ml-1">Serial</span>' : ''}
                </td>
                <td>${escapeHtml(line.product_code)}</td>
                <td class="text-center">${qtyHtml}</td>
                <td>${serialHtml}</td>
                <td class="text-center">
                    <button type="button" class="btn btn-sm btn-outline-danger btn-zero-line" title="Kosongkan Baris">
                        <i class="bi bi-x"></i> Hapus
                    </button>
                </td>
            `;

            tbody.appendChild(tr);
        });
    }

    function escapeHtml(str) {
        if (!str) return '';
        return String(str)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#039;');
    }
});
</script>
@endpush

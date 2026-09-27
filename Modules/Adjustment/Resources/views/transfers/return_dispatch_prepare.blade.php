@extends('layouts.app')

@section('title', 'Persiapan Batch Retur Transfer Stok')

@section('breadcrumb')
    <ol class="breadcrumb border-0 m-0">
        <li class="breadcrumb-item"><a href="{{ route('home') }}">Home</a></li>
        <li class="breadcrumb-item"><a href="{{ route('transfers.index') }}">Transfer Stok</a></li>
        <li class="breadcrumb-item"><a href="{{ route('transfers.show', $transfer->id) }}">{{ $transfer->document_number ?? ('#' . $transfer->id) }}</a></li>
        <li class="breadcrumb-item active">Persiapan Batch Retur</li>
    </ol>
@endsection

@section('content')
    <div class="container-fluid" id="return-dispatch-app">
        <div class="row">
            <div class="col-12">
                <div class="card">
                    <div class="card-header d-flex justify-content-between align-items-center">
                        <div>
                            <h4 class="card-title mb-0">Persiapan Batch Retur (V2 Return Dispatch)</h4>
                            <small class="text-muted">Dokumen: {{ $transfer->document_number ?? ('#' . $transfer->id) }} | Kondisi: {{ $movement->stock_condition }} | Revisi Batch: #{{ $movement->revision }} | Batch ID: {{ $movement->return_batch_id }}</small>
                        </div>
                        <div>
                            <span class="badge badge-info p-2" id="movement-status-badge">{{ $movement->status }}</span>
                        </div>
                    </div>
                    <div class="card-body">
                        <div class="alert alert-secondary py-2">
                            Batch retur ini bersifat independen: Anda dapat mengirim sebagian produk yang menjadi kewajiban retur dan mengirim sisanya melalui batch terpisah di kemudian hari.
                        </div>

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
                                <form action="{{ route('transfers.movements.return.cancel', ['transfer' => $transfer->id, 'movement' => $movement->id]) }}" method="POST" class="d-inline" onsubmit="return confirm('Batalkan draft batch retur ini?');">
                                    @csrf
                                    <button type="submit" class="btn btn-outline-danger">
                                        <i class="bi bi-trash mr-1"></i> Batalkan Draft
                                    </button>
                                </form>
                            </div>
                        </div>

                        <div class="table-responsive mb-4">
                            <table class="table table-sm table-bordered" id="obligated-products-table">
                                <thead class="bg-light">
                                    <tr>
                                        <th>Produk Kewajiban Retur</th>
                                        <th class="text-center">Kondisi</th>
                                        @if($canViewSystemStock)
                                            <th class="text-center">Kapasitas Tersedia</th>
                                            <th class="text-center">Sisa Kewajiban</th>
                                        @endif
                                    </tr>
                                </thead>
                                <tbody>
                                </tbody>
                            </table>
                        </div>

                        {{-- Line Table --}}
                        <div class="table-responsive">
                            <table class="table table-bordered table-striped" id="lines-table">
                                <thead class="bg-light">
                                    <tr>
                                        <th>Produk</th>
                                        <th>Kode</th>
                                        <th class="text-center" style="width: 220px;">Perhitungan Fisik</th>
                                        <th class="text-center">Seri Terpilih</th>
                                        <th class="text-center">Status Konfirmasi</th>
                                        <th class="text-center" style="width: 140px;">Aksi</th>
                                    </tr>
                                </thead>
                                <tbody>
                                </tbody>
                            </table>
                        </div>

                        {{-- Action Footers --}}
                        <div class="d-flex justify-content-between align-items-center mt-4 pt-3 border-top">
                            <a href="{{ route('transfers.show', $transfer->id) }}" class="btn btn-secondary">
                                <i class="bi bi-arrow-left mr-1"></i> Kembali ke Detail
                            </a>
                            <div>
                                <form action="{{ route('transfers.movements.return.submit', ['transfer' => $transfer->id, 'movement' => $movement->id]) }}" method="POST" class="d-inline" id="submit-movement-form">
                                    @csrf
                                    <input type="hidden" name="lock_version" id="form-lock-version" value="{{ $movement->lock_version }}">
                                    <button type="submit" class="btn btn-success btn-lg" id="btn-submit-movement">
                                        <i class="bi bi-send-check mr-1"></i> Ajukan Batch untuk Persetujuan
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
document.addEventListener('DOMContentLoaded', function() {
    let currentLockVersion = {{ (int) $movement->lock_version }};
    const transferId = {{ (int) $transfer->id }};
    const movementId = {{ (int) $movement->id }};
    const canViewSystemStock = {{ $canViewSystemStock ? 'true' : 'false' }};

    const scanInput = document.getElementById('scan-input');
    const btnScan = document.getElementById('btn-scan');
    const scanFeedback = document.getElementById('scan-feedback');
    const formLockVersion = document.getElementById('form-lock-version');

    function escapeHtml(str) {
        if (str === null || str === undefined) return '';
        return String(str)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#039;');
    }

    function showFeedback(message, type = 'danger') {
        const safeMessage = escapeHtml(message);
        scanFeedback.innerHTML = `<div class="alert alert-${type} alert-dismissible fade show mb-0 py-2" role="alert">
            ${safeMessage}
            <button type="button" class="close py-2" data-dismiss="alert" aria-label="Close">
                <span aria-hidden="true">&times;</span>
            </button>
        </div>`;
    }

    function renderObligatedProducts(projection) {
        const tbody = document.querySelector('#obligated-products-table tbody');
        if (!tbody) return;
        tbody.innerHTML = '';
        (projection.obligated_products || []).forEach(p => {
            const row = document.createElement('tr');
            let extraCols = '';
            if (canViewSystemStock) {
                extraCols = `
                    <td class="text-center">${escapeHtml(p.available_capacity)}</td>
                    <td class="text-center">${escapeHtml(p.outstanding_quantity)}</td>
                `;
            }
            row.innerHTML = `
                <td>${escapeHtml(p.product_name)}</td>
                <td class="text-center">${escapeHtml(p.stock_condition)}</td>
                ${extraCols}
            `;
            tbody.appendChild(row);
        });
    }

    function renderProjection(projection) {
        if (!projection) return;
        currentLockVersion = projection.lock_version;
        if (formLockVersion) {
            formLockVersion.value = currentLockVersion;
        }

        renderObligatedProducts(projection);

        const tbody = document.querySelector('#lines-table tbody');
        tbody.innerHTML = '';

        (projection.lines || []).forEach(line => {
            const isSerialized = line.is_serialized;
            const safeProductName = escapeHtml(line.product_name);
            const safeProductCode = escapeHtml(line.product_code);
            const safeQty = parseInt(line.quantity, 10) || 0;

            let serialsHtml = '<span class="text-muted small">-</span>';
            if (isSerialized) {
                if (line.serials && line.serials.length > 0) {
                    serialsHtml = line.serials.map(s => `<span class="badge badge-light border mr-1">${escapeHtml(s.serial_number)}</span>`).join('');
                } else {
                    serialsHtml = '<span class="text-muted small">Belum ada seri discan</span>';
                }
            }

            let qtyColHtml = '';
            if (isSerialized) {
                qtyColHtml = `<input type="text" class="form-control text-center font-weight-bold line-qty-input" readonly value="${safeQty}">`;
            } else {
                qtyColHtml = `
                    <div class="input-group">
                        <input type="number" step="1" min="0" class="form-control text-center line-qty-input" value="${safeQty}">
                        <div class="input-group-append">
                            <button class="btn btn-outline-secondary btn-save-qty" type="button" title="Simpan Jumlah">
                                <i class="bi bi-check"></i>
                            </button>
                        </div>
                    </div>
                `;
            }

            const confirmBadge = line.count_confirmed
                ? `<span class="badge badge-success"><i class="bi bi-check-circle mr-1"></i> Terkonfirmasi</span>`
                : `<span class="badge badge-warning"><i class="bi bi-clock mr-1"></i> Belum Dikonfirmasi</span>`;

            let actionBtn = '';
            if (parseFloat(line.quantity) === 0 && !line.count_confirmed) {
                actionBtn = `<button type="button" class="btn btn-sm btn-outline-info btn-confirm-zero">Konfirmasi 0</button>`;
            }

            const row = document.createElement('tr');
            row.setAttribute('data-line-id', line.line_id);
            row.setAttribute('data-product-id', line.product_id);
            row.setAttribute('data-is-serialized', isSerialized ? '1' : '0');
            row.innerHTML = `
                <td>
                    <strong>${safeProductName}</strong>
                    ${isSerialized ? '<span class="badge badge-secondary ml-1">Serial</span>' : ''}
                </td>
                <td>${safeProductCode}</td>
                <td class="text-center">${qtyColHtml}</td>
                <td><div class="serials-list">${serialsHtml}</div></td>
                <td class="text-center confirmation-cell">${confirmBadge}</td>
                <td class="text-center">${actionBtn}</td>
            `;
            tbody.appendChild(row);
        });

        attachRowEvents();
    }

    function performScan() {
        const query = scanInput.value.trim();
        if (!query) return;

        scanFeedback.innerHTML = '';
        fetch(`{{ url('transfers') }}/${transferId}/movements/${movementId}/return/scan`, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': '{{ csrf_token() }}',
                'Accept': 'application/json',
            },
            body: JSON.stringify({
                query: query,
                lock_version: currentLockVersion,
            })
        })
        .then(res => res.json())
        .then(data => {
            if (data.status === 'resolved') {
                showFeedback(data.message, 'success');
                renderProjection(data.projection);
                scanInput.value = '';
            } else if (data.status === 'rejected' || data.status === 'not_found' || data.status === 'error') {
                showFeedback(data.message || 'Gagal memproses scan.', 'danger');
                if (data.projection) renderProjection(data.projection);
            } else if (data.status === 'ambiguous') {
                const candidates = data.candidates || [];
                let candidateListHtml = '<div class="list-group mt-2">';
                candidates.forEach(cand => {
                    const safeName = escapeHtml(cand.product_name);
                    const safeCode = escapeHtml(cand.product_code);
                    const safeDesc = escapeHtml(cand.description || `${safeName} (${safeCode})`);
                    const prodId = parseInt(cand.product_id, 10);
                    const isSerial = cand.serial_number_required ? true : false;
                    candidateListHtml += `
                        <button type="button" class="list-group-item list-group-item-action d-flex justify-content-between align-items-center py-2 btn-select-candidate" data-product-id="${prodId}" data-is-serialized="${isSerial ? '1' : '0'}">
                            <div>
                                <strong>${safeName}</strong>
                                ${isSerial ? '<span class="badge badge-secondary ml-1">Serial</span>' : ''}
                                <small class="text-muted d-block">${safeDesc}</small>
                            </div>
                            <span class="btn btn-sm ${isSerial ? 'btn-outline-secondary' : 'btn-primary'}">${isSerial ? 'Scan Seri' : 'Pilih'}</span>
                        </button>
                    `;
                });
                candidateListHtml += '</div>';

                scanFeedback.innerHTML = `
                    <div class="alert alert-warning alert-dismissible fade show mb-0 py-2" role="alert">
                        <div><strong>Hasil scan ambigu:</strong> Ditemukan beberapa produk yang cocok. Silakan pilih produk:</div>
                        ${candidateListHtml}
                        <button type="button" class="close py-2" data-dismiss="alert" aria-label="Close">
                            <span aria-hidden="true">&times;</span>
                        </button>
                    </div>
                `;

                scanFeedback.querySelectorAll('.btn-select-candidate').forEach(btn => {
                    btn.onclick = function() {
                        const pId = parseInt(this.getAttribute('data-product-id'), 10);
                        const isSerial = this.getAttribute('data-is-serialized') === '1';
                        if (!pId) return;

                        if (isSerial) {
                            showFeedback('Produk memerlukan nomor seri. Silakan scan nomor seri produk secara langsung.', 'warning');
                            scanInput.value = '';
                            scanInput.focus();
                            return;
                        }

                        const currentLine = data.projection?.lines?.find(l => parseInt(l.product_id, 10) === pId);
                        const currentQty = currentLine ? parseInt(currentLine.quantity, 10) || 0 : 0;
                        const newQty = currentQty + 1;

                        setLineQty(pId, newQty, true);
                        scanInput.value = '';
                        scanInput.focus();
                    };
                });

                if (data.projection) renderProjection(data.projection);
            }
            scanInput.focus();
        })
        .catch(function() {
            showFeedback('Terjadi kesalahan jaringan.', 'danger');
        });
    }

    function setLineQty(productId, quantity, confirmed = true) {
        fetch(`{{ url('transfers') }}/${transferId}/movements/${movementId}/return/set-quantity`, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': '{{ csrf_token() }}',
                'Accept': 'application/json',
            },
            body: JSON.stringify({
                product_id: productId,
                quantity: quantity,
                count_confirmed: confirmed,
                lock_version: currentLockVersion,
            })
        })
        .then(res => res.json())
        .then(data => {
            if (data.status === 'success') {
                showFeedback('Jumlah berhasil disimpan.', 'success');
                renderProjection(data.projection);
            } else {
                showFeedback(data.message || 'Gagal memperbarui jumlah.', 'danger');
            }
        })
        .catch(function() {
            showFeedback('Terjadi kesalahan jaringan.', 'danger');
        });
    }

    function attachRowEvents() {
        document.querySelectorAll('.btn-save-qty').forEach(btn => {
            btn.onclick = function() {
                const tr = this.closest('tr');
                const productId = tr.getAttribute('data-product-id');
                const input = tr.querySelector('.line-qty-input');
                const qty = input.value;
                setLineQty(productId, qty, true);
            };
        });

        document.querySelectorAll('.btn-confirm-zero').forEach(btn => {
            btn.onclick = function() {
                const tr = this.closest('tr');
                const productId = tr.getAttribute('data-product-id');
                setLineQty(productId, 0, true);
            };
        });
    }

    btnScan.addEventListener('click', performScan);
    scanInput.addEventListener('keydown', function(e) {
        if (e.key === 'Enter') {
            e.preventDefault();
            performScan();
        }
    });

    // Initial projection fetch to populate obligated products / existing lines
    fetch(`{{ url('transfers') }}/${transferId}/movements/return/prepare?batch={{ $movement->return_batch_id }}`, {
        headers: { 'Accept': 'application/json' }
    })
    .then(res => res.json())
    .then(function(projection) { renderProjection(projection); })
    .catch(function() {});
});
</script>
@endpush

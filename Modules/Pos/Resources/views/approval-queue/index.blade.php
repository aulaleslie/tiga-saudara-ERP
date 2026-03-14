@extends('layouts.app')

@section('title', 'Antrian Persetujuan POS')

@section('content')
    <div class="container-fluid">
        <div class="d-flex justify-content-between align-items-center mb-3">
            <h1 class="h3 mb-0 text-gray-800">Antrian Persetujuan POS</h1>
            <div>
                <span id="queue-last-updated" class="text-muted small me-2">Memuat...</span>
                <button id="queue-refresh-btn" class="btn btn-sm btn-outline-primary">
                    <i class="bi bi-arrow-clockwise"></i> Segarkan Sekarang
                </button>
            </div>
        </div>

        <div class="card shadow mb-4">
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-hover table-striped mb-0" id="queue-table">
                        <thead class="table-light">
                            <tr>
                                <th>Waktu Pengajuan</th>
                                <th>Kasir</th>
                                <th>Sesi POS</th>
                                <th>Tindakan Pengecualian</th>
                                <th>Target</th>
                                <th class="text-center">Aksi</th>
                            </tr>
                        </thead>
                        <tbody id="queue-table-body">
                            <tr>
                                <td colspan="6" class="text-center text-muted py-4">Memuat antrian...</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>

    <!-- Approve Modal -->
    <div class="modal fade" id="approveModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Konfirmasi Persetujuan</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <form id="approveForm">
                    <div class="modal-body">
                        <p>Apakah Anda yakin ingin menyetujui permohonan ini?</p>
                        <div class="mb-3">
                            <label for="approveNote" class="form-label">Catatan (Opsional)</label>
                            <input type="text" class="form-control" id="approveNote" name="note" autocomplete="off">
                        </div>
                        <input type="hidden" id="approveRequestId">
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Batal</button>
                        <button type="submit" class="btn btn-success" id="btnSubmitApprove">Setujui</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Reject Modal -->
    <div class="modal fade" id="rejectModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Tolak Permohonan</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <form id="rejectForm">
                    <div class="modal-body">
                        <div class="mb-3">
                            <label for="rejectReason" class="form-label">Alasan Penolakan <span class="text-danger">*</span></label>
                            <input type="text" class="form-control" id="rejectReason" name="reason" required autocomplete="off">
                        </div>
                        <input type="hidden" id="rejectRequestId">
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Batal</button>
                        <button type="submit" class="btn btn-danger" id="btnSubmitReject">Tolak</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
@endsection

@push('page_scripts')
<script>
document.addEventListener('DOMContentLoaded', function () {
    const tableBody = document.getElementById('queue-table-body');
    const lastUpdatedLabel = document.getElementById('queue-last-updated');
    const refreshBtn = document.getElementById('queue-refresh-btn');

    let approveModalInstance = null;
    let rejectModalInstance = null;

    // Use Bootstrap's programmatic API with fallback to data-bs attributes
    const showApproveModal = () => {
        const approveModalEl = document.getElementById('approveModal');
        if (window.bootstrap?.Modal) {
            if (!approveModalInstance) {
                approveModalInstance = new window.bootstrap.Modal(approveModalEl);
            }
            approveModalInstance.show();
        } else {
            // Fallback: just add show class and backdrop
            approveModalEl.classList.add('show');
            approveModalEl.style.display = 'block';
            document.body.classList.add('modal-open');
            const backdrop = document.createElement('div');
            backdrop.className = 'modal-backdrop fade show';
            document.body.appendChild(backdrop);
            backdrop.id = 'approve-modal-backdrop';
        }
    };

    const showRejectModal = () => {
        const rejectModalEl = document.getElementById('rejectModal');
        if (window.bootstrap?.Modal) {
            if (!rejectModalInstance) {
                rejectModalInstance = new window.bootstrap.Modal(rejectModalEl);
            }
            rejectModalInstance.show();
        } else {
            rejectModalEl.classList.add('show');
            rejectModalEl.style.display = 'block';
            document.body.classList.add('modal-open');
            const backdrop = document.createElement('div');
            backdrop.className = 'modal-backdrop fade show';
            document.body.appendChild(backdrop);
            backdrop.id = 'reject-modal-backdrop';
        }
    };

    const hideApproveModal = () => {
        const approveModalEl = document.getElementById('approveModal');
        if (approveModalInstance) {
            approveModalInstance.hide();
        } else {
            approveModalEl.classList.remove('show');
            approveModalEl.style.display = 'none';
            document.body.classList.remove('modal-open');
            const backdrop = document.getElementById('approve-modal-backdrop');
            if (backdrop) backdrop.remove();
        }
    };

    const hideRejectModal = () => {
        const rejectModalEl = document.getElementById('rejectModal');
        if (rejectModalInstance) {
            rejectModalInstance.hide();
        } else {
            rejectModalEl.classList.remove('show');
            rejectModalEl.style.display = 'none';
            document.body.classList.remove('modal-open');
            const backdrop = document.getElementById('reject-modal-backdrop');
            if (backdrop) backdrop.remove();
        }
    };

    const formatDate = (isoString) => {
        if (!isoString) return '-';
        const date = new Date(isoString);
        return date.toLocaleTimeString('id-ID', { hour: '2-digit', minute: '2-digit', second: '2-digit' });
    };

    const actionLabels = {
        'CART_CLEAR': '<span class="badge bg-danger">Hapus Keranjang</span>',
        'LINE_REMOVE': '<span class="badge bg-warning text-dark">Hapus Baris Produk</span>',
        'QTY_REDUCE': '<span class="badge bg-info text-dark">Kurangi Qty Produk</span>',
        'PRICE_OVERRIDE': '<span class="badge bg-primary">Ubah Harga Jual</span>'
    };

    const loadQueue = () => {
        refreshBtn.disabled = true;
        
        fetch('{{ route('pos.supervisor.approval-requests.data') }}?status=PENDING', {
            headers: {
                'Accept': 'application/json',
                'X-Requested-With': 'XMLHttpRequest'
            }
        })
        .then(response => {
            if (!response.ok) throw new Error('Respons jaringan tidak valid');
            return response.json();
        })
        .then(responseJson => {
            tableBody.innerHTML = '';
            const data = responseJson.data || [];
            
            if (data.length === 0) {
                tableBody.innerHTML = '<tr><td colspan="6" class="text-center text-muted py-4">Tidak ada antrian persetujuan saat ini.</td></tr>';
            } else {
                data.forEach(req => {
                    const tr = document.createElement('tr');
                    
                    let actionHtml = actionLabels[req.action_type] || req.action_type;
                    let targetHtml = req.target_type === 'pos_session'
                        ? `Sesi #${req.target_id}`
                        : `Cart Line #${req.target_id}`;

                    if (req.action_type === 'PRICE_OVERRIDE') {
                        const requestedPrice = Number(req.request_payload?.unit_price || 0);
                        const reason = req.request_payload?.reason ? `<br><small class="text-muted">${req.request_payload.reason}</small>` : '';
                        targetHtml = `Cart Line #${req.target_id}<br><small class="text-primary">Harga diminta: ${requestedPrice.toLocaleString('id-ID')}</small>${reason}`;
                    }

                    tr.innerHTML = `
                        <td>${formatDate(req.created_at)}</td>
                        <td>
                            <strong>${req.requester ? req.requester.name : '-'}</strong>
                        </td>
                        <td>Sesi #${req.pos_session_id}</td>
                        <td>${actionHtml}</td>
                        <td><small class="text-muted">${targetHtml}</small></td>
                        <td class="text-center">
                            <button class="btn btn-sm btn-success btn-approve" data-id="${req.id}">Setujui</button>
                            <button class="btn btn-sm btn-danger btn-reject" data-id="${req.id}">Tolak</button>
                        </td>
                    `;
                    tableBody.appendChild(tr);
                });
            }
            
            lastUpdatedLabel.textContent = `Terakhir diperbarui: ${new Date().toLocaleTimeString('id-ID')}`;
            refreshBtn.disabled = false;
        })
        .catch(error => {
            console.error('Gagal mengambil antrian:', error);
            tableBody.innerHTML = '<tr><td colspan="6" class="text-center text-danger py-4">Gagal memuat data antrian. Silakan coba lagi.</td></tr>';
            refreshBtn.disabled = false;
        });
    };

    loadQueue();
    refreshBtn.addEventListener('click', loadQueue);
    setInterval(loadQueue, 15000);

    tableBody.addEventListener('click', function(e) {
        if (e.target.classList.contains('btn-approve')) {
            const id = e.target.getAttribute('data-id');
            document.getElementById('approveRequestId').value = id;
            document.getElementById('approveNote').value = '';
            showApproveModal();
        } else if (e.target.classList.contains('btn-reject')) {
            const id = e.target.getAttribute('data-id');
            document.getElementById('rejectRequestId').value = id;
            document.getElementById('rejectReason').value = '';
            showRejectModal();
        }
    });

    document.getElementById('approveForm').addEventListener('submit', function(e) {
        e.preventDefault();
        const id = document.getElementById('approveRequestId').value;
        const note = document.getElementById('approveNote').value;
        const btn = document.getElementById('btnSubmitApprove');

        btn.disabled = true;
        btn.textContent = 'Menyimpan...';

        fetch(`/pos/supervisor/approval-requests/${id}/approve`, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'Accept': 'application/json',
                'X-Requested-With': 'XMLHttpRequest',
                'X-CSRF-TOKEN': '{{ csrf_token() }}'
            },
            body: JSON.stringify({ note: note })
        })
        .then(response => {
            console.log('Approve response status:', response.status);
            if (!response.ok) {
                return response.json().then(err => {
                    console.error('Approve error:', err);
                    throw new Error(err.message || 'Error occurred');
                });
            }
            return response.json();
        })
        .then(data => {
            console.log('Approve success:', data);
            hideApproveModal();
            loadQueue();
            alert('Persetujuan berhasil disimpan.');
        })
        .catch(err => {
            console.error('Approve catch:', err);
            alert('Gagal menyetujui: ' + err.message);
        })
        .finally(() => {
            btn.disabled = false;
            btn.textContent = 'Setujui';
        });
    });

    document.getElementById('rejectForm').addEventListener('submit', function(e) {
        e.preventDefault();
        const id = document.getElementById('rejectRequestId').value;
        const reason = document.getElementById('rejectReason').value;
        const btn = document.getElementById('btnSubmitReject');
        
        btn.disabled = true;
        btn.textContent = 'Menyimpan...';

        fetch(`/pos/supervisor/approval-requests/${id}/reject`, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'Accept': 'application/json',
                'X-Requested-With': 'XMLHttpRequest',
                'X-CSRF-TOKEN': '{{ csrf_token() }}'
            },
            body: JSON.stringify({ reason: reason })
        })
        .then(response => {
            if (!response.ok) {
                return response.json().then(err => { throw new Error(err.message || 'Error occurred'); });
            }
            return response.json();
        })
        .then(() => {
            hideRejectModal();
            loadQueue();
            alert('Penolakan berhasil disimpan.');
        })
        .catch(err => {
            alert('Gagal menolak: ' + err.message);
        })
        .finally(() => {
            btn.disabled = false;
            btn.textContent = 'Tolak';
        });
    });
});
</script>
@endpush

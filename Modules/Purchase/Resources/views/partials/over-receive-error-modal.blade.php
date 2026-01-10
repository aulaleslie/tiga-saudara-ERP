{{-- Over-Receiving Error Modal --}}
<div class="modal fade" id="overReceiveErrorModal" tabindex="-1" role="dialog" aria-labelledby="overReceiveErrorModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-lg" role="document">
        <div class="modal-content">
            <div class="modal-header bg-danger text-white">
                <h5 class="modal-title" id="overReceiveErrorModalLabel">
                    <i class="bi bi-exclamation-triangle-fill mr-2"></i>
                    Tidak Dapat Menyetujui Penerimaan
                </h5>
                <button type="button" class="close text-white" onclick="$('#overReceiveErrorModal').modal('hide');" aria-label="Close">
                    <span aria-hidden="true">&times;</span>
                </button>
            </div>
            <div class="modal-body">
                <div class="alert alert-warning">
                    <strong>Jumlah penerimaan melebihi jumlah pesanan!</strong><br>
                    Silakan tolak penerimaan ini atau sesuaikan jumlah penerimaan.
                </div>
                <div class="table-responsive">
                    <table class="table table-sm table-bordered">
                        <thead class="thead-light">
                            <tr>
                                <th>Produk</th>
                                <th class="text-right">Dipesan</th>
                                <th class="text-right">Sudah Diterima</th>
                                <th class="text-right">Penerimaan Ini</th>
                                <th class="text-right text-danger">Kelebihan</th>
                            </tr>
                        </thead>
                        <tbody id="overReceiveErrorTableBody">
                            {{-- Populated by JavaScript --}}
                        </tbody>
                    </table>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" onclick="$('#overReceiveErrorModal').modal('hide');">Tutup</button>
                <button type="button" class="btn btn-danger" id="overReceiveRejectBtn">
                    <i class="bi bi-x-lg mr-1"></i> Tolak Penerimaan
                </button>
            </div>
        </div>
    </div>
</div>

<script>
    function showOverReceiveError(data, rejectUrl) {
        const tableBody = document.getElementById('overReceiveErrorTableBody');
        tableBody.innerHTML = '';
        
        data.details.forEach(function(item) {
            const row = document.createElement('tr');
            row.innerHTML = `
                <td>
                    <strong>${item.product_name}</strong><br>
                    <small class="text-muted">${item.product_code}</small>
                </td>
                <td class="text-right">${item.ordered_quantity}</td>
                <td class="text-right">${item.already_received}</td>
                <td class="text-right">${item.pending_quantity}</td>
                <td class="text-right text-danger font-weight-bold">+${item.excess}</td>
            `;
            tableBody.appendChild(row);
        });
        
        // Set up reject button
        const rejectBtn = document.getElementById('overReceiveRejectBtn');
        rejectBtn.onclick = function() {
            // Close current modal
            $('#overReceiveErrorModal').modal('hide');
            // Open the reject modal for this receiving (if exists)
            if (rejectUrl) {
                const rejectModalId = '#rejectModal' + data.received_note_id;
                if ($(rejectModalId).length) {
                    $(rejectModalId).modal('show');
                } else {
                    // Fallback: navigate to the page for rejection
                    alert('Silakan gunakan tombol Tolak pada daftar penerimaan.');
                }
            }
        };
        
        $('#overReceiveErrorModal').modal('show');
    }
    
    function handleApproveReceiving(form, event) {
        event.preventDefault();
        
        const url = form.action;
        const csrfToken = form.querySelector('input[name="_token"]').value;
        
        fetch(url, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': csrfToken,
                'Accept': 'application/json',
                'X-Requested-With': 'XMLHttpRequest'
            },
            body: JSON.stringify({})
        })
        .then(response => response.json().then(data => ({ status: response.status, body: data })))
        .then(({ status, body }) => {
            if (body.success) {
                // Success - reload the page
                window.location.reload();
            } else if (body.error === 'over_receiving') {
                // Over-receiving error - show modal
                showOverReceiveError(body, url.replace('/approve', '/reject'));
            } else {
                // Other error
                alert(body.message || 'Terjadi kesalahan. Silakan coba lagi.');
            }
        })
        .catch(error => {
            console.error('Error:', error);
            alert('Terjadi kesalahan. Silakan coba lagi.');
        });
    }
</script>

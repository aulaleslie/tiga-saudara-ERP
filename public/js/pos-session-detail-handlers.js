/**
 * POS Session Detail Handlers
 * Handles cash event filtering and checkout drill-down modals
 */

document.addEventListener('DOMContentLoaded', function () {
    // 1. Initialize Cash Event Filtering
    const filterButtons = document.querySelectorAll('.filter-btn');
    const eventRows = document.querySelectorAll('.event-row');

    filterButtons.forEach(button => {
        button.addEventListener('click', function () {
            const filter = this.dataset.filter;

            // Update button states
            filterButtons.forEach(btn => {
                btn.classList.remove('btn-dark', 'active');
                btn.classList.add('btn-light');
            });
            this.classList.remove('btn-light');
            this.classList.add('btn-dark', 'active');

            // Toggle rows
            eventRows.forEach(row => {
                if (filter === 'all' || row.dataset.eventType === filter) {
                    row.style.display = '';
                } else {
                    row.style.display = 'none';
                }
            });
        });
    });

    // 2. Initialize Transaction Detail Modal
    const transactionRows = document.querySelectorAll('.transaction-row');
    const modalBody = document.getElementById('checkoutModalBody');
    const modalTitle = document.getElementById('checkoutModalTitle');
    const printBtn = document.getElementById('printReceiptBtn');
    const $checkoutModal = $('#checkoutDetailModal'); // Use jQuery for BS4

    transactionRows.forEach(row => {
        row.addEventListener('click', function () {
            const checkoutId = this.dataset.checkoutId;
            openCheckoutDetail(checkoutId);
        });
    });

    function openCheckoutDetail(checkoutId) {
        // Show loading state
        modalBody.innerHTML = `
            <div class="text-center py-5">
                <div class="spinner-border text-primary" role="status">
                    <span class="visually-hidden">Loading...</span>
                </div>
                <div class="mt-2 text-muted">Mengambil detail transaksi...</div>
            </div>
        `;
        $checkoutModal.modal('show'); // BS4/jQuery syntax

        // Fetch data
        fetch(`/pos/sessions/${SESSION_ID}/checkouts/${checkoutId}`)
            .then(response => {
                if (!response.ok) throw new Error('Gagal memuat detail transaksi');
                return response.json();
            })
            .then(checkout => {
                populateCheckoutModal(checkout);
                printBtn.href = `/pos/sell/checkout/${checkout.id}/receipt/reprint`;
            })
            .catch(error => {
                modalBody.innerHTML = `
                    <div class="alert alert-danger m-3" role="alert">
                        <i class="bi bi-exclamation-circle me-2"></i> ${error.message}
                    </div>
                `;
            });
    }

    function populateCheckoutModal(checkout) {
        modalTitle.textContent = `Transaksi ${checkout.receipt_number}`;

        const customerName = checkout.customer ? checkout.customer.customer_name : 'Walk-in';
        const cashierName = checkout.cashier ? checkout.cashier.name : 'Unknown';
        const paymentMethod = checkout.payment_method ? checkout.payment_method.name : 'Unknown';
        const finalizedAt = formatDate(checkout.finalized_at);

        let linesHtml = '';
        if (checkout.transaction && checkout.transaction.lines) {
            checkout.transaction.lines.forEach(line => {
                linesHtml += `
                    <tr>
                        <td>
                            <div class="fw-bold">${line.product_name_snapshot}</div>
                            <small class="text-muted">${line.product_code_snapshot || ''}</small>
                        </td>
                        <td class="text-center">${parseFloat(line.qty)}</td>
                        <td class="text-end">${formatCurrency(line.unit_price)}</td>
                        <td class="text-end fw-bold">${formatCurrency(parseFloat(line.qty) * parseFloat(line.unit_price))}</td>
                    </tr>
                `;
            });
        }

        modalBody.innerHTML = `
            <div class="p-4 bg-light border-bottom">
                <div class="row g-3">
                    <div class="col-6 col-md-3">
                        <div class="small text-muted mb-1">Pelanggan</div>
                        <div class="fw-bold">${customerName}</div>
                    </div>
                    <div class="col-6 col-md-3">
                        <div class="small text-muted mb-1">Kasir</div>
                        <div class="fw-bold">${cashierName}</div>
                    </div>
                    <div class="col-6 col-md-3">
                        <div class="small text-muted mb-1">Pembayaran</div>
                        <div class="fw-bold"><span class="badge bg-white text-dark border">${paymentMethod}</span></div>
                    </div>
                    <div class="col-6 col-md-3">
                        <div class="small text-muted mb-1">Waktu</div>
                        <div class="fw-bold">${finalizedAt}</div>
                    </div>
                </div>
            </div>
            <div class="p-0">
                <table class="table table-sm mb-0">
                    <thead class="table-light">
                        <tr class="small text-muted">
                            <th class="ps-4">Item</th>
                            <th class="text-center">Qty</th>
                            <th class="text-end">Harga</th>
                            <th class="text-end pe-4">Subtotal</th>
                        </tr>
                    </thead>
                    <tbody>
                        ${linesHtml}
                    </tbody>
                    <tfoot class="bg-white">
                        <tr>
                            <td colspan="3" class="text-end ps-4 border-0">Subtotal</td>
                            <td class="text-end pe-4 border-0">${formatCurrency(checkout.subtotal)}</td>
                        </tr>
                        ${parseFloat(checkout.discount_total) > 0 ? `
                        <tr>
                            <td colspan="3" class="text-end ps-4 border-0">Diskon</td>
                            <td class="text-end pe-4 border-0 text-danger">-${formatCurrency(checkout.discount_total)}</td>
                        </tr>
                        ` : ''}
                        ${parseFloat(checkout.tax_total) > 0 ? `
                        <tr>
                            <td colspan="3" class="text-end ps-4 border-0">Pajak</td>
                            <td class="text-end pe-4 border-0">${formatCurrency(checkout.tax_total)}</td>
                        </tr>
                        ` : ''}
                        <tr class="fw-bold border-top" style="font-size: 1.1rem;">
                            <td colspan="3" class="text-end ps-4 border-0 p-3">GRAND TOTAL</td>
                            <td class="text-end pe-4 border-0 p-3 text-primary">${formatCurrency(checkout.grand_total)}</td>
                        </tr>
                    </tfoot>
                </table>
            </div>
        `;
    }

    // Helper: Formatter for Currency
    function formatCurrency(value) {
        return new Intl.NumberFormat('id-ID', {
            style: 'currency',
            currency: 'IDR',
            minimumFractionDigits: 0
        }).format(value);
    }

    // Helper: Formatter for Date
    function formatDate(dateString) {
        if (!dateString) return '-';
        const date = new Date(dateString);
        return new Intl.DateTimeFormat('id-ID', {
            day: '2-digit',
            month: '2-digit',
            year: 'numeric',
            hour: '2-digit',
            minute: '2-digit'
        }).format(date);
    }
});

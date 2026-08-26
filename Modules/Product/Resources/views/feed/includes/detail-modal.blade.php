<!-- Detail Modal -->
<div class="modal fade" id="priceFeedDetailModal" tabindex="-1" role="dialog" aria-labelledby="priceFeedDetailModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-centered" role="document">
        <div class="modal-content border-0 shadow">
            <div class="modal-header bg-light">
                <h5 class="modal-title font-weight-bold text-dark" id="priceFeedDetailModalLabel">
                    <i class="bi bi-clock-history mr-2 text-primary"></i> Detail Pembaruan Produk & Harga
                </h5>
                <button type="button" class="close" data-dismiss="modal" aria-label="Close">
                    <span aria-hidden="true">&times;</span>
                </button>
            </div>
            <div class="modal-body p-4" id="priceFeedModalBody">
                <div class="text-center py-4" id="priceFeedModalSpinner">
                    <div class="spinner-border text-primary" role="status">
                        <span class="sr-only">Loading...</span>
                    </div>
                </div>
                <div id="priceFeedModalContent" style="display: none;"></div>
            </div>
            <div class="modal-footer bg-light py-2">
                <button type="button" class="btn btn-secondary btn-sm" data-dismiss="modal">Tutup</button>
            </div>
        </div>
    </div>
</div>

<script>
let priceFeedTriggerElement = null;

$('#priceFeedDetailModal').on('hidden.bs.modal', function () {
    if (priceFeedTriggerElement) {
        priceFeedTriggerElement.focus();
        priceFeedTriggerElement = null;
    }
});

function openPriceFeedModal(eventId, triggerEl) {
    if (triggerEl) {
        priceFeedTriggerElement = triggerEl;
    }
    const modal = $('#priceFeedDetailModal');
    const spinner = $('#priceFeedModalSpinner');
    const content = $('#priceFeedModalContent');

    spinner.show();
    content.hide().empty();
    modal.modal('show');

    fetch('/products/price-feed-history/' + eventId)
        .then(response => {
            if (!response.ok) throw new Error('Gagal mengambil data detail.');
            return response.json();
        })
        .then(data => {
            let html = `
                <div class="mb-3 pb-3 border-bottom">
                    <div class="h5 font-weight-bold mb-1">${escapeHtml(data.subject_name)} ${data.subject_code ? '(' + escapeHtml(data.subject_code) + ')' : ''}</div>
                    <div class="small text-muted">
                        <span class="mr-3"><i class="bi bi-tag mr-1"></i> ${escapeHtml(formatEventType(data.event_type))}</span>
                        <span class="mr-3"><i class="bi bi-person mr-1"></i> ${escapeHtml(data.actor_name || 'System')} (${escapeHtml(data.source)})</span>
                        <span><i class="bi bi-clock mr-1"></i> ${escapeHtml(data.occurred_at)}</span>
                    </div>
                </div>
            `;

            data.sections.forEach(section => {
                html += `<div class="card border-0 shadow-sm mb-3">
                    <div class="card-header bg-light font-weight-bold py-2">
                        <i class="bi bi-building mr-1"></i> ${escapeHtml(section.setting_name)}
                    </div>
                    <div class="card-body p-3">`;

                if (data.event_type.endsWith('_created')) {
                    html += `<table class="table table-sm table-bordered mb-0">
                        <thead class="thead-light">
                            <tr><th>Field Harga</th><th>Nilai Awal</th></tr>
                        </thead>
                        <tbody>`;
                    if (section.after) {
                        Object.keys(section.after).forEach(key => {
                            html += `<tr><td>${escapeHtml(formatPriceKey(key))}</td><td>Rp ${formatCurrency(section.after[key])}</td></tr>`;
                        });
                    }
                    html += `</tbody></table>`;
                } else {
                    html += `<table class="table table-sm table-bordered mb-0">
                        <thead class="thead-light">
                            <tr><th>Field Harga</th><th>Sebelum</th><th>Sesudah</th></tr>
                        </thead>
                        <tbody>`;
                    if (section.before && section.after) {
                        Object.keys(section.after).forEach(key => {
                            html += `<tr>
                                <td>${escapeHtml(formatPriceKey(key))}</td>
                                <td class="text-muted">Rp ${formatCurrency(section.before[key])}</td>
                                <td class="font-weight-bold text-success">Rp ${formatCurrency(section.after[key])}</td>
                            </tr>`;
                        });
                    }
                    html += `</tbody></table>`;
                }

                html += `</div></div>`;
            });

            content.html(html);
            spinner.hide();
            content.show();
        })
        .catch(err => {
            spinner.hide();
            content.html(`<div class="alert alert-danger mb-0">${escapeHtml(err.message)}</div>`).show();
        });
}

function escapeHtml(str) {
    if (str === null || str === undefined) return '';
    return String(str)
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;')
        .replace(/'/g, '&#039;');
}

function formatEventType(type) {
    switch (type) {
        case 'product_created': return 'Produk Baru';
        case 'product_price_updated': return 'Update Harga Produk';
        case 'bundle_created': return 'Paket Baru';
        case 'bundle_price_updated': return 'Update Harga Paket';
        default: return type;
    }
}

function formatPriceKey(key) {
    switch (key) {
        case 'sale_price': return 'Harga Jual';
        case 'tier_1_price': return 'Harga Tier 1';
        case 'tier_2_price': return 'Harga Tier 2';
        case 'last_purchase_price': return 'Harga Beli Terakhir';
        case 'bundle_sale_price': return 'Harga Jual Paket';
        default: return key;
    }
}

function formatCurrency(val) {
    if (val === null || val === undefined) return '0';
    return Number(val).toLocaleString('id-ID');
}
</script>

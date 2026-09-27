@extends('layouts.app')

@section('title', 'Koreksi Penerimaan Pembelian')

@section('content')
<div class="container-fluid">
    <div class="row mb-4">
        <div class="col-12">
            <h1 class="h3">Koreksi Penerimaan Pembelian</h1>
        </div>
    </div>

    <div class="row mb-4">
        <div class="col-md-6">
            <div class="card">
                <div class="card-body">
                    <div class="row">
                        <div class="col-md-6">
                            <p class="text-muted small mb-1">Referensi</p>
                            <p class="font-weight-bold">{{ $purchase->reference }}</p>
                        </div>
                        <div class="col-md-6">
                            <p class="text-muted small mb-1">Pemasok</p>
                            <p class="font-weight-bold">{{ $purchase->supplier->supplier_name }}</p>
                        </div>
                    </div>
                    <div class="row mt-3">
                        <div class="col-md-6">
                            <p class="text-muted small mb-1">Status</p>
                            <p class="font-weight-bold">{{ $purchase->status }}</p>
                        </div>
                        <div class="col-md-6">
                            <p class="text-muted small mb-1">Total Saat Ini</p>
                            <p class="font-weight-bold">{{ format_currency($purchase->total_amount) }}</p>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <form id="correctionForm" method="POST" class="row">
        @csrf

        <!-- Protected Fields Display -->
        <div class="col-12 mb-4">
            <div class="card border-info">
                <div class="card-header bg-light">
                    <h6 class="mb-0">Informasi Terlindungi (Tidak dapat diubah)</h6>
                </div>
                <div class="card-body">
                    <div class="row">
                        <div class="col-md-4">
                            <p class="text-muted small mb-1">Produk & Qty</p>
                            <p>{{ $purchase->purchaseDetails->count() }} item</p>
                        </div>
                        <div class="col-md-4">
                            <p class="text-muted small mb-1">Tanggal</p>
                            <p>{{ \Carbon\Carbon::parse($purchase->date)->format('d-m-Y') }}</p>
                        </div>
                        <div class="col-md-4">
                            <p class="text-muted small mb-1">Lokasi Terima</p>
                            <p>{{ $purchase->receivedNotes?->first()?->location->name ?? '-' }}</p>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Line Corrections -->
        <div class="col-12 mb-4">
            <div class="card">
                <div class="card-header">
                    <h6 class="mb-0">Koreksi Detail Baris</h6>
                </div>
                <div class="card-body">
                    @foreach ($purchase->purchaseDetails as $detail)
                        <div class="card mb-3 border">
                            <div class="card-body">
                                <p class="text-muted small mb-3">
                                    {{ $detail->product?->product_name }} ({{ $detail->quantity }} {{ $detail->product?->baseUnit?->unit_name }})
                                </p>
                                <div class="form-group">
                                    <input type="number"
                                           name="line_corrections[{{ $loop->index }}][detail_id]"
                                           value="{{ $detail->id }}"
                                           hidden>
                                </div>
                                <div class="row">
                                    <div class="col-md-6">
                                        <div class="form-group">
                                            <label class="form-label small">Harga Satuan (Rp.)</label>
                                            <input type="number"
                                                   step="0.01"
                                                   name="line_corrections[{{ $loop->index }}][unit_price]"
                                                   value="{{ $detail->unit_price }}"
                                                   class="form-control correction-input">
                                        </div>
                                    </div>
                                    <div class="col-md-6">
                                        <div class="form-group">
                                            <label class="form-label small">Diskon Baris (Rp.)</label>
                                            <input type="number"
                                                   step="0.01"
                                                   name="line_corrections[{{ $loop->index }}][product_discount_amount]"
                                                   value="{{ $detail->product_discount_amount ?? 0 }}"
                                                   class="form-control correction-input">
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    @endforeach
                </div>
            </div>
        </div>

        <!-- Header Adjustments -->
        <div class="col-12 mb-4">
            <div class="card border-primary">
                <div class="card-header bg-light">
                    <h6 class="mb-0">Penyesuaian Dokumen</h6>
                </div>
                <div class="card-body">
                    <div class="row">
                        <div class="col-md-6">
                            <div class="form-group">
                                <label class="form-label">Diskon Global (Rp.)</label>
                                <input type="number"
                                       step="0.01"
                                       name="global_discount_amount"
                                       id="global_discount_amount"
                                       value="{{ $purchase->discount_amount ?? 0 }}"
                                       class="form-control correction-input">
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="form-group">
                                <label class="form-label">Biaya Pengiriman (Rp.)</label>
                                <input type="number"
                                       step="0.01"
                                       name="shipping_amount"
                                       id="shipping_amount"
                                       value="{{ $purchase->shipping_amount ?? 0 }}"
                                       class="form-control correction-input">
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Payment Selection (Multiple Payments Only) -->
        @if ($purchase->purchasePayments->where('status', 'ACTIVE')->count() > 1)
            <div class="col-12 mb-4">
                <div class="card">
                    <div class="card-header">
                        <h6 class="mb-0">Pembayaran untuk Dikoreksi</h6>
                    </div>
                    <div class="card-body">
                        <div class="form-group">
                            <label class="form-label">Pilih Pembayaran</label>
                            <select name="selected_payment_id"
                                    id="selected_payment_id"
                                    class="form-control correction-input">
                                <option value="">-- Pilih Pembayaran --</option>
                                @foreach ($purchase->purchasePayments->where('status', 'ACTIVE') as $payment)
                                    <option value="{{ $payment->id }}">
                                        Pembayaran {{ $loop->iteration }}: {{ format_currency($payment->amount) }}
                                    </option>
                                @endforeach
                            </select>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Payment Preview (Multiple Payments) -->
            <div class="col-12 mb-4">
                <div id="paymentPreviewContainer" style="display: none;">
                    <div class="card border-success">
                        <div class="card-header bg-light">
                            <h6 class="mb-0">Pratinjau Pembayaran</h6>
                        </div>
                        <div class="card-body">
                            <div id="previewContent">
                                <!-- Populated by JavaScript -->
                            </div>
                            <div id="previewErrors"></div>
                        </div>
                    </div>
                </div>
            </div>
        @else
            <!-- Single or Zero Payment Preview -->
            <div class="col-12 mb-4">
                <div class="card border-success">
                    <div class="card-header bg-light">
                        <h6 class="mb-0">Pratinjau Pembayaran</h6>
                    </div>
                    <div class="card-body">
                        <div id="singlePreviewContent">
                            <!-- Populated by JavaScript -->
                        </div>
                    </div>
                </div>
            </div>
        @endif

        <!-- Reason -->
        <div class="col-12 mb-4">
            <div class="card">
                <div class="card-header">
                    <h6 class="mb-0">Alasan Koreksi</h6>
                </div>
                <div class="card-body">
                    <div class="form-group">
                        <label class="form-label">Alasan Koreksi <span class="text-danger">*</span></label>
                        <textarea name="reason"
                                  required
                                  rows="4"
                                  class="form-control"
                                  placeholder="Jelaskan alasan koreksi penerimaan..."></textarea>
                    </div>
                </div>
            </div>
        </div>

        <!-- Hidden token field for multi-payment -->
        @if ($purchase->purchasePayments->where('status', 'ACTIVE')->count() > 1)
            <input type="hidden" name="confirmation_token" id="confirmation_token" value="">
        @endif

        <!-- Correction History -->
        @if ($purchase->corrections->count() > 0)
            <div class="col-12 mb-4">
                <div class="card border-warning">
                    <div class="card-header bg-light">
                        <h6 class="mb-0">Riwayat Koreksi</h6>
                    </div>
                    <div class="card-body">
                        @foreach ($purchase->corrections->reverse() as $correction)
                            <div class="mb-3 pb-3 border-bottom">
                                <p class="text-muted small mb-1">
                                    {{ $correction->actor->name }} - {{ $correction->created_at->format('d-m-Y H:i') }}
                                </p>
                                <p class="small mb-0">{{ $correction->reason }}</p>
                            </div>
                        @endforeach
                    </div>
                </div>
            </div>
        @endif

        <!-- Actions -->
        <div class="col-12">
            <div class="btn-group" role="group">
                <button type="submit" id="submitBtn" class="btn btn-primary">
                    <i class="bi bi-check-circle"></i> Simpan Koreksi
                </button>
                <a href="{{ route('purchases.show', $purchase) }}" class="btn btn-secondary">
                    <i class="bi bi-x-circle"></i> Batal
                </a>
            </div>
        </div>
    </form>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    const form = document.getElementById('correctionForm');
    const correctionInputs = document.querySelectorAll('.correction-input');
    const selectedPaymentSelect = document.getElementById('selected_payment_id');
    const submitBtn = document.getElementById('submitBtn');
    const confirmationTokenField = document.getElementById('confirmation_token');
    const hasMultiplePayments = {{ $purchase->purchasePayments->where('status', 'ACTIVE')->count() > 1 ? 'true' : 'false' }};

    let previewValid = !hasMultiplePayments; // Single/zero payment don't need preview validation
    let previewTimeout; // For debouncing

    function updatePreview() {
        // Debounce input events (~400ms)
        clearTimeout(previewTimeout);
        previewTimeout = setTimeout(() => {
            if (!hasMultiplePayments) {
                updateSinglePaymentPreview();
                return;
            }

            const selectedPaymentId = selectedPaymentSelect?.value;
            if (!selectedPaymentId) {
                document.getElementById('paymentPreviewContainer').style.display = 'none';
                previewValid = false;
                submitBtn.disabled = true;
                if (confirmationTokenField) {
                    confirmationTokenField.value = '';
                }
                return;
            }

            const lineCorrections = getLineCorrections();
            const globalDiscount = document.getElementById('global_discount_amount')?.value || null;
            const shipping = document.getElementById('shipping_amount')?.value || null;

            fetch("{{ route('purchases.correction.payment-preview', $purchase) }}", {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': document.querySelector('[name="_token"]').value,
                },
                body: JSON.stringify({
                    line_corrections: lineCorrections,
                    global_discount_amount: globalDiscount,
                    shipping_amount: shipping,
                    selected_payment_id: parseInt(selectedPaymentId),
                })
            })
            .then(response => response.json())
            .then(data => {
                const container = document.getElementById('paymentPreviewContainer');
                const content = document.getElementById('previewContent');
                const errors = document.getElementById('previewErrors');

                if (data.success) {
                    previewValid = true;
                    errors.innerHTML = '';

                    let html = `
                        <div class="row mb-3">
                            <div class="col-md-6">
                                <p class="small text-muted mb-1">Total Saat Ini</p>
                                <p class="font-weight-bold">${formatCurrency(data.current_total)}</p>
                            </div>
                            <div class="col-md-6">
                                <p class="small text-muted mb-1">Total Terkoreksi</p>
                                <p class="font-weight-bold">${formatCurrency(data.corrected_total)}</p>
                            </div>
                        </div>
                        <div class="row mb-3">
                            <div class="col-md-6">
                                <p class="small text-muted mb-1">Delta</p>
                                <p class="font-weight-bold">${formatCurrency(data.total_delta)}</p>
                            </div>
                            <div class="col-md-6">
                                <p class="small text-muted mb-1">Status Pembayaran</p>
                                <p class="font-weight-bold">${data.resulting_status}</p>
                            </div>
                        </div>
                        <div class="border-top pt-3">
                            <p class="small font-weight-bold mb-2">Pembayaran Terpilih:</p>
                            <div class="pl-3 small">
                                <p class="mb-1">Jumlah Sekarang: ${formatCurrency(data.selected_payment.current_amount)}</p>
                                <p class="mb-0">Jumlah Terkoreksi: ${formatCurrency(data.selected_payment.corrected_amount)}</p>
                            </div>
                        </div>
                        <div class="border-top pt-3 mt-3">
                            <p class="small font-weight-bold mb-2">Ringkasan:</p>
                            <div class="pl-3 small">
                                <p class="mb-1">Total Dibayar: ${formatCurrency(data.resulting_paid_amount)}</p>
                                <p class="mb-0">Sisa Tagihan: ${formatCurrency(data.resulting_due_amount)}</p>
                            </div>
                        </div>
                    `;

                    content.innerHTML = html;
                    container.style.display = 'block';

                    if (confirmationTokenField && data.confirmation_token) {
                        confirmationTokenField.value = data.confirmation_token;
                    }

                    submitBtn.disabled = false;
                } else {
                    previewValid = false;
                    submitBtn.disabled = true;
                    errors.innerHTML = `<div class="alert alert-danger mb-0"><strong>Error:</strong> ${data.error || 'Unknown error'}</div>`;

                    if (data.validation_errors && data.validation_errors.length > 0) {
                        const errorList = data.validation_errors.map(e => `<li>${e}</li>`).join('');
                        errors.innerHTML += `<ul class="list-disc pl-3 mt-2">${errorList}</ul>`;
                    }

                    container.style.display = 'block';
                }
            })
            .catch(error => {
                console.error('Preview error:', error);
                previewValid = false;
                submitBtn.disabled = true;
                document.getElementById('previewErrors').innerHTML = `<div class="alert alert-danger mb-0">Error loading preview</div>`;
                document.getElementById('paymentPreviewContainer').style.display = 'block';
            });
        }, 400); // Debounce delay
    }

    function updateSinglePaymentPreview() {
        const lineCorrections = getLineCorrections();
        const globalDiscount = document.getElementById('global_discount_amount')?.value || null;
        const shipping = document.getElementById('shipping_amount')?.value || null;

        fetch("{{ route('purchases.correction.payment-preview', $purchase) }}", {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': document.querySelector('[name="_token"]').value,
            },
            body: JSON.stringify({
                line_corrections: lineCorrections,
                global_discount_amount: globalDiscount,
                shipping_amount: shipping,
            })
        })
        .then(response => response.json())
        .then(data => {
            const content = document.getElementById('singlePreviewContent');

            if (data.success) {
                let html = `
                    <div class="row mb-3">
                        <div class="col-md-6">
                            <p class="small text-muted mb-1">Total Saat Ini</p>
                            <p class="font-weight-bold">${formatCurrency(data.current_total)}</p>
                        </div>
                        <div class="col-md-6">
                            <p class="small text-muted mb-1">Total Terkoreksi</p>
                            <p class="font-weight-bold">${formatCurrency(data.corrected_total)}</p>
                        </div>
                    </div>
                    <div class="row">
                        <div class="col-md-6">
                            <p class="small text-muted mb-1">Delta</p>
                            <p class="font-weight-bold">${formatCurrency(data.total_delta)}</p>
                        </div>
                        <div class="col-md-6">
                            <p class="small text-muted mb-1">Status Pembayaran</p>
                            <p class="font-weight-bold">${data.resulting_status}</p>
                        </div>
                    </div>
                `;

                if (data.active_payment_count === 1) {
                    html += `
                        <div class="border-top pt-3 mt-3">
                            <p class="small font-weight-bold mb-2">Pembayaran Tunggal:</p>
                            <div class="pl-3 small">
                                <p class="mb-1">Jumlah Sekarang: ${formatCurrency(data.selected_payment.current_amount)}</p>
                                <p class="mb-0">Jumlah Terkoreksi: ${formatCurrency(data.selected_payment.corrected_amount)}</p>
                            </div>
                        </div>
                    `;
                } else if (data.active_payment_count === 0) {
                    html += `
                        <div class="border-top pt-3 mt-3">
                            <p class="small text-muted">Tidak ada pembayaran aktif.</p>
                        </div>
                    `;
                }

                content.innerHTML = html;
            }
        })
        .catch(error => {
            console.error('Preview error:', error);
        });
    }

    function getLineCorrections() {
        const lineCorrections = [];
        const formData = new FormData(form);

        // Extract line corrections from form
        for (let key of formData.keys()) {
            if (key.startsWith('line_corrections[')) {
                const match = key.match(/line_corrections\[(\d+)\]\[(\w+)\]/);
                if (match) {
                    const index = parseInt(match[1]);
                    const field = match[2];

                    if (!lineCorrections[index]) {
                        lineCorrections[index] = {};
                    }

                    const value = formData.get(key);
                    lineCorrections[index][field] = field === 'detail_id' ? parseInt(value) :
                                                      (field === 'unit_price' || field === 'product_discount_amount') ?
                                                      (value ? parseFloat(value) : null) : value;
                }
            }
        }

        return lineCorrections.filter(Boolean);
    }

    function formatCurrency(num) {
        return new Intl.NumberFormat('id-ID', { style: 'currency', currency: 'IDR' }).format(Math.round(num));
    }

    // Event listeners - change events trigger immediate; input events debounced
    correctionInputs.forEach(input => {
        input.addEventListener('change', () => {
            clearTimeout(previewTimeout);
            updatePreview();
        });
        input.addEventListener('input', updatePreview);
    });

    if (selectedPaymentSelect) {
        selectedPaymentSelect.addEventListener('change', () => {
            clearTimeout(previewTimeout);
            updatePreview();
        });
    }

    // Form submission
    form.addEventListener('submit', function(e) {
        e.preventDefault();

        if (hasMultiplePayments && !confirmationTokenField.value) {
            alert('Silakan lakukan pratinjau pembayaran terlebih dahulu');
            return;
        }

        submitForm();
    });

    function submitForm() {
        const lineCorrections = getLineCorrections();
        const formData = new FormData(form);
        const data = {
            reason: formData.get('reason'),
            line_corrections: lineCorrections,
            global_discount_amount: formData.get('global_discount_amount') ?
                                   parseFloat(formData.get('global_discount_amount')) : null,
            shipping_amount: formData.get('shipping_amount') ?
                            parseFloat(formData.get('shipping_amount')) : null,
            selected_payment_id: formData.get('selected_payment_id') ?
                                parseInt(formData.get('selected_payment_id')) : null,
            confirmation_token: confirmationTokenField?.value || null,
        };

        fetch("{{ route('purchases.correction.store', $purchase) }}", {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': document.querySelector('[name="_token"]').value,
            },
            body: JSON.stringify(data)
        })
        .then(response => {
            if (!response.ok) {
                return response.json().then(err => { throw err; });
            }
            return response.json();
        })
        .then(data => {
            // Show success in alert and redirect
            const alertDiv = document.createElement('div');
            alertDiv.className = 'alert alert-success alert-dismissible fade show';
            alertDiv.setAttribute('role', 'alert');
            alertDiv.innerHTML = `
                <strong>Sukses!</strong> Koreksi berhasil disimpan.
                <button type="button" class="close" data-dismiss="alert" aria-label="Close">
                    <span aria-hidden="true">&times;</span>
                </button>
            `;
            form.insertBefore(alertDiv, form.firstChild);
            setTimeout(() => {
                window.location.href = "{{ route('purchases.show', $purchase) }}";
            }, 1000);
        })
        .catch(error => {
            console.error('Error:', error);
            const alertDiv = document.createElement('div');
            alertDiv.className = 'alert alert-danger alert-dismissible fade show';
            alertDiv.setAttribute('role', 'alert');
            alertDiv.innerHTML = `
                <strong>Error:</strong> ${error.error || error.message || 'Unknown error'}
                <button type="button" class="close" data-dismiss="alert" aria-label="Close">
                    <span aria-hidden="true">&times;</span>
                </button>
            `;
            form.insertBefore(alertDiv, form.firstChild);
        });
    }

    // Initial preview load
    updatePreview();
});
</script>
@endsection

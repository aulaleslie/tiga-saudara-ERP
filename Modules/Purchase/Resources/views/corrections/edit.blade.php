@extends('layouts.app')

@section('content')
<div class="container mx-auto px-4 py-6">
    <h1 class="text-2xl font-bold mb-6">Koreksi Penerimaan Pembelian</h1>

    <div class="grid grid-cols-2 gap-4 mb-6">
        <div>
            <p class="text-gray-600">Referensi</p>
            <p class="font-semibold">{{ $purchase->reference }}</p>
        </div>
        <div>
            <p class="text-gray-600">Pemasok</p>
            <p class="font-semibold">{{ $purchase->supplier->supplier_name }}</p>
        </div>
        <div>
            <p class="text-gray-600">Status</p>
            <p class="font-semibold">{{ $purchase->status }}</p>
        </div>
        <div>
            <p class="text-gray-600">Total Saat Ini</p>
            <p class="font-semibold">Rp. {{ number_format($purchase->total_amount, 2, ',', '.') }}</p>
        </div>
    </div>

    <form id="correctionForm" method="POST" class="space-y-6">
        @csrf

        <!-- Protected Fields Display -->
        <div class="bg-gray-50 p-4 rounded mb-6">
            <h3 class="font-semibold mb-3">Informasi Terlindungi (Tidak dapat diubah)</h3>
            <div class="grid grid-cols-2 gap-4 text-sm">
                <div>
                    <p class="text-gray-600">Produk & Qty</p>
                    <p>{{ $purchase->purchaseDetails->count() }} item</p>
                </div>
                <div>
                    <p class="text-gray-600">Tanggal</p>
                    <p>{{ \Carbon\Carbon::parse($purchase->date)->format('d-m-Y') }}</p>
                </div>
                <div>
                    <p class="text-gray-600">Lokasi Terima</p>
                    <p>{{ $purchase->receivedNotes?->first()?->location->name ?? '-' }}</p>
                </div>
            </div>
        </div>

        <!-- Line Corrections -->
        <div>
            <h3 class="font-semibold mb-3">Koreksi Detail Baris</h3>
            @foreach ($purchase->purchaseDetails as $detail)
                <div class="border rounded p-4 mb-3">
                    <p class="text-sm text-gray-600 mb-2">
                        {{ $detail->product?->product_name }} ({{ $detail->quantity }} {{ $detail->product?->baseUnit?->unit_name }})
                    </p>
                    <div class="grid grid-cols-2 gap-4">
                        <div>
                            <label class="block text-sm font-medium">Harga Satuan (Rp.)</label>
                            <input type="number"
                                   name="line_corrections[{{ $loop->index }}][detail_id]"
                                   value="{{ $detail->id }}"
                                   hidden>
                            <input type="number"
                                   step="0.01"
                                   name="line_corrections[{{ $loop->index }}][unit_price]"
                                   value="{{ $detail->unit_price }}"
                                   class="correction-input w-full border rounded px-2 py-1">
                        </div>
                        <div>
                            <label class="block text-sm font-medium">Diskon Baris (Rp.)</label>
                            <input type="number"
                                   step="0.01"
                                   name="line_corrections[{{ $loop->index }}][product_discount_amount]"
                                   value="{{ $detail->product_discount_amount ?? 0 }}"
                                   class="correction-input w-full border rounded px-2 py-1">
                        </div>
                    </div>
                </div>
            @endforeach
        </div>

        <!-- Header Adjustments -->
        <div class="bg-blue-50 p-4 rounded">
            <h3 class="font-semibold mb-3">Penyesuaian Dokumen</h3>
            <div class="grid grid-cols-2 gap-4">
                <div>
                    <label class="block text-sm font-medium">Diskon Global (Rp.)</label>
                    <input type="number"
                           step="0.01"
                           name="global_discount_amount"
                           id="global_discount_amount"
                           value="{{ $purchase->discount_amount ?? 0 }}"
                           class="correction-input w-full border rounded px-2 py-1">
                </div>
                <div>
                    <label class="block text-sm font-medium">Biaya Pengiriman (Rp.)</label>
                    <input type="number"
                           step="0.01"
                           name="shipping_amount"
                           id="shipping_amount"
                           value="{{ $purchase->shipping_amount ?? 0 }}"
                           class="correction-input w-full border rounded px-2 py-1">
                </div>
            </div>
        </div>

        <!-- Payment Selection (Multiple Payments Only) -->
        @if ($purchase->purchasePayments->where('status', 'ACTIVE')->count() > 1)
            <div>
                <label class="block text-sm font-medium mb-2">Pembayaran untuk Dikoreksi</label>
                <select name="selected_payment_id"
                        id="selected_payment_id"
                        class="correction-input w-full border rounded px-2 py-1">
                    <option value="">-- Pilih Pembayaran --</option>
                    @foreach ($purchase->purchasePayments->where('status', 'ACTIVE') as $payment)
                        <option value="{{ $payment->id }}">
                            Pembayaran {{ $loop->iteration }}: Rp. {{ number_format($payment->amount, 2, ',', '.') }}
                        </option>
                    @endforeach
                </select>
            </div>

            <!-- Payment Preview (Multiple Payments) -->
            <div id="paymentPreviewContainer" style="display: none;" class="bg-green-50 p-4 rounded">
                <h3 class="font-semibold mb-3">Pratinjau Pembayaran</h3>
                <div id="previewContent" class="space-y-2 text-sm">
                    <!-- Populated by JavaScript -->
                </div>
                <div id="previewErrors" class="mt-3 text-red-600"></div>
            </div>
        @else
            <!-- Single or Zero Payment Preview -->
            <div id="singlePaymentPreviewContainer" class="bg-green-50 p-4 rounded">
                <h3 class="font-semibold mb-3">Pratinjau Pembayaran</h3>
                <div id="singlePreviewContent" class="space-y-2 text-sm">
                    <!-- Populated by JavaScript -->
                </div>
            </div>
        @endif

        <!-- Reason -->
        <div>
            <label class="block text-sm font-medium mb-2">Alasan Koreksi *</label>
            <textarea name="reason"
                      required
                      rows="4"
                      class="w-full border rounded px-2 py-1"
                      placeholder="Jelaskan alasan koreksi penerimaan..."></textarea>
        </div>

        <!-- Hidden token field for multi-payment -->
        @if ($purchase->purchasePayments->where('status', 'ACTIVE')->count() > 1)
            <input type="hidden" name="confirmation_token" id="confirmation_token" value="">
        @endif

        <!-- Correction History -->
        @if ($purchase->corrections->count() > 0)
            <div class="bg-yellow-50 p-4 rounded">
                <h3 class="font-semibold mb-3">Riwayat Koreksi</h3>
                @foreach ($purchase->corrections->reverse() as $correction)
                    <div class="border-b pb-2 mb-2 last:border-b-0 last:mb-0">
                        <p class="text-sm text-gray-600">
                            {{ $correction->actor->name }} - {{ $correction->created_at->format('d-m-Y H:i') }}
                        </p>
                        <p class="text-sm">{{ $correction->reason }}</p>
                    </div>
                @endforeach
            </div>
        @endif

        <!-- Actions -->
        <div class="flex gap-2">
            <button type="submit" id="submitBtn" class="px-4 py-2 bg-blue-600 text-white rounded hover:bg-blue-700">
                Simpan Koreksi
            </button>
            <a href="{{ route('purchases.show', $purchase) }}" class="px-4 py-2 bg-gray-300 text-gray-700 rounded hover:bg-gray-400">
                Batal
            </a>
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

    function updatePreview() {
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
                    <div class="grid grid-cols-2 gap-4">
                        <div><strong>Total Saat Ini:</strong> Rp. ${formatNumber(data.current_total)}</div>
                        <div><strong>Total Terkoreksi:</strong> Rp. ${formatNumber(data.corrected_total)}</div>
                        <div><strong>Delta:</strong> Rp. ${formatNumber(data.total_delta)}</div>
                        <div><strong>Status Pembayaran:</strong> ${data.resulting_status}</div>
                    </div>
                    <div class="mt-3 border-t pt-3">
                        <p><strong>Pembayaran Terpilih:</strong></p>
                        <div class="ml-4 text-sm">
                            <p>Jumlah Sekarang: Rp. ${formatNumber(data.selected_payment.current_amount)}</p>
                            <p>Jumlah Terkoreksi: Rp. ${formatNumber(data.selected_payment.corrected_amount)}</p>
                        </div>
                    </div>
                    <div class="mt-3 border-t pt-3">
                        <p><strong>Ringkasan:</strong></p>
                        <div class="ml-4 text-sm">
                            <p>Total Dibayar: Rp. ${formatNumber(data.resulting_paid_amount)}</p>
                            <p>Sisa Tagihan: Rp. ${formatNumber(data.resulting_due_amount)}</p>
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
                errors.innerHTML = `<p class="text-red-600"><strong>Error:</strong> ${data.error || 'Unknown error'}</p>`;

                if (data.validation_errors && data.validation_errors.length > 0) {
                    const errorList = data.validation_errors.map(e => `<li>${e}</li>`).join('');
                    errors.innerHTML += `<ul class="list-disc ml-4">${errorList}</ul>`;
                }

                container.style.display = 'block';
            }
        })
        .catch(error => {
            console.error('Preview error:', error);
            previewValid = false;
            submitBtn.disabled = true;
            document.getElementById('previewErrors').innerHTML = `<p class="text-red-600">Error loading preview</p>`;
            document.getElementById('paymentPreviewContainer').style.display = 'block';
        });
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
                    <div class="grid grid-cols-2 gap-4">
                        <div><strong>Total Saat Ini:</strong> Rp. ${formatNumber(data.current_total)}</div>
                        <div><strong>Total Terkoreksi:</strong> Rp. ${formatNumber(data.corrected_total)}</div>
                        <div><strong>Delta:</strong> Rp. ${formatNumber(data.total_delta)}</div>
                        <div><strong>Status Pembayaran:</strong> ${data.resulting_status}</div>
                    </div>
                `;

                if (data.active_payment_count === 1) {
                    html += `
                        <div class="mt-3 border-t pt-3">
                            <p><strong>Pembayaran Tunggal:</strong></p>
                            <div class="ml-4 text-sm">
                                <p>Jumlah Sekarang: Rp. ${formatNumber(data.selected_payment.current_amount)}</p>
                                <p>Jumlah Terkoreksi: Rp. ${formatNumber(data.selected_payment.corrected_amount)}</p>
                            </div>
                        </div>
                    `;
                } else if (data.active_payment_count === 0) {
                    html += `
                        <div class="mt-3 border-t pt-3">
                            <p class="text-sm">Tidak ada pembayaran aktif.</p>
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

    function formatNumber(num) {
        return new Intl.NumberFormat('id-ID').format(Math.round(num));
    }

    // Event listeners
    correctionInputs.forEach(input => {
        input.addEventListener('change', updatePreview);
        input.addEventListener('input', updatePreview);
    });

    if (selectedPaymentSelect) {
        selectedPaymentSelect.addEventListener('change', updatePreview);
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
            alert('Koreksi berhasil disimpan');
            window.location.href = "{{ route('purchases.show', $purchase) }}";
        })
        .catch(error => {
            console.error('Error:', error);
            alert('Error: ' + (error.error || error.message || 'Unknown error'));
        });
    }

    // Initial preview load
    updatePreview();
});
</script>
@endsection

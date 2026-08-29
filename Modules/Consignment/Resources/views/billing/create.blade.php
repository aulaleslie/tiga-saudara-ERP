@extends('layouts.app')

@section('title', 'Form Konversi Tagihan Supplier - ' . $confirmation->confirmation_number)

@section('breadcrumb')
    <ol class="breadcrumb border-0 m-0">
        <li class="breadcrumb-item"><a href="{{ route('home') }}">Beranda</a></li>
        <li class="breadcrumb-item"><a href="{{ route('consignments.billing.index') }}">Tagihan Siap Konversi</a></li>
        <li class="breadcrumb-item active">Konversi {{ $confirmation->confirmation_number }}</li>
    </ol>
@endsection

@section('content')
    <div class="container-fluid">
        <div class="card border-0 shadow-sm mb-4">
            <div class="card-header bg-white border-bottom">
                <h5 class="mb-0">Konversi Tagihan Supplier (Consignment Billing Conversion)</h5>
                <small class="text-muted">Konfirmasi: <strong>{{ $confirmation->confirmation_number }}</strong> | Supplier: <strong>{{ $confirmation->supplier->supplier_name }}</strong></small>
            </div>
            <div class="card-body">
                <form method="POST" action="{{ route('consignments.billing.convert', $confirmation->id) }}" enctype="multipart/form-data" id="conversionForm">
                    @csrf
                    
                    <div class="row mb-4">
                        <div class="col-md-4 form-group">
                            <label for="supplier_invoice_number">No. Faktur / Invoice Supplier <span class="text-danger">*</span></label>
                            <input type="text" name="supplier_invoice_number" id="supplier_invoice_number" class="form-control @error('supplier_invoice_number') is-invalid @enderror" value="{{ old('supplier_invoice_number') }}" required placeholder="Contoh: INV/2026/08/001">
                            @error('supplier_invoice_number') <div class="invalid-feedback">{{ $message }}</div> @enderror
                        </div>

                        <div class="col-md-4 form-group">
                            <label for="invoice_date">Tanggal Faktur / Invoice <span class="text-danger">*</span></label>
                            <input type="date" name="invoice_date" id="invoice_date" class="form-control @error('invoice_date') is-invalid @enderror" value="{{ old('invoice_date', date('Y-m-d')) }}" required>
                            @error('invoice_date') <div class="invalid-feedback">{{ $message }}</div> @enderror
                        </div>

                        <div class="col-md-4 form-group">
                            <label for="due_date">Tanggal Jatuh Tempo</label>
                            <input type="date" name="due_date" id="due_date" class="form-control @error('due_date') is-invalid @enderror" value="{{ old('due_date') }}">
                            @error('due_date') <div class="invalid-feedback">{{ $message }}</div> @enderror
                        </div>

                        <div class="col-md-4 form-group">
                            <label for="payment_term_id">Syarat Pembayaran (Payment Term)</label>
                            <select name="payment_term_id" id="payment_term_id" class="form-control">
                                <option value="">-- Pilih Syarat Pembayaran --</option>
                                @foreach($paymentTerms as $term)
                                    <option value="{{ $term->id }}" data-longevity="{{ $term->longevity }}" {{ old('payment_term_id') == $term->id ? 'selected' : '' }}>
                                        {{ $term->name }} ({{ $term->longevity }} hari)
                                    </option>
                                @endforeach
                            </select>
                        </div>

                        <div class="col-md-4 form-group">
                            <label for="tax_ref_no">No. Referensi Pajak / No. Faktur Pajak</label>
                            <input type="text" name="tax_ref_no" id="tax_ref_no" class="form-control" value="{{ old('tax_ref_no') }}" placeholder="Contoh: 010.000-26.00000001">
                        </div>

                        <div class="col-md-4 form-group">
                            <label for="attachments">Lampiran Faktur / Invoice (PDF / Image)</label>
                            <input type="file" name="attachments[]" id="attachments" class="form-control-file" multiple>
                        </div>

                        <div class="col-md-12 form-group">
                            <label for="billing_notes">Catatan Tagihan</label>
                            <textarea name="billing_notes" id="billing_notes" class="form-control" rows="2" placeholder="Catatan opsional mengenai penagihan ini...">{{ old('billing_notes') }}</textarea>
                        </div>
                    </div>

                    <h5 class="mb-3">Preview Rincian Line Item Purchase (Commercial Snapshots)</h5>
                    <div class="table-responsive mb-4">
                        <table class="table table-bordered table-sm">
                            <thead class="thead-light">
                                <tr>
                                    <th>Kode Produk</th>
                                    <th>Nama Produk</th>
                                    <th class="text-right">Qty Billed</th>
                                    <th class="text-right">Harga DPP / Unit</th>
                                    <th class="text-right">Subtotal</th>
                                    <th class="text-right">Pajak</th>
                                    <th class="text-right">Total Line</th>
                                </tr>
                            </thead>
                            <tbody>
                                @php
                                    $previewService = app(\Modules\Consignment\Services\ConsignmentBillingPreviewService::class);
                                    $preview = $previewService->generatePreview($confirmation->id, session('setting_id'), [
                                        'supplier_invoice_number' => 'PREVIEW',
                                        'invoice_date' => date('Y-m-d'),
                                        'due_date' => date('Y-m-d'),
                                    ]);
                                @endphp

                                @if($preview['valid'])
                                    @foreach($preview['lines'] as $line)
                                        <tr>
                                            <td>{{ $line['product_code'] }}</td>
                                            <td>{{ $line['product_name'] }}</td>
                                            <td class="text-right font-weight-bold">{{ number_format($line['quantity'], 3) }}</td>
                                            <td class="text-right">Rp {{ number_format($line['unit_price'], 2, ',', '.') }}</td>
                                            <td class="text-right">Rp {{ number_format($line['sub_total'], 2, ',', '.') }}</td>
                                            <td class="text-right">Rp {{ number_format($line['product_tax_amount'], 2, ',', '.') }} ({{ $line['tax_rate'] }}%)</td>
                                            <td class="text-right font-weight-bold">Rp {{ number_format($line['total_amount'], 2, ',', '.') }}</td>
                                        </tr>
                                    @endforeach
                                @else
                                    <tr>
                                        <td colspan="7" class="text-center text-danger py-3">
                                            Preview tidak dapat dibuat: {{ implode('; ', $preview['blockers']) }}
                                        </td>
                                    </tr>
                                @endif
                            </tbody>
                            @if($preview['valid'])
                                <tfoot class="bg-light font-weight-bold">
                                    <tr>
                                        <td colspan="4" class="text-right">Total:</td>
                                        <td class="text-right">Rp {{ number_format($preview['totals']['sub_total'], 2, ',', '.') }}</td>
                                        <td class="text-right">Rp {{ number_format($preview['totals']['tax_amount'], 2, ',', '.') }}</td>
                                        <td class="text-right text-primary">Rp {{ number_format($preview['totals']['total_amount'], 2, ',', '.') }}</td>
                                    </tr>
                                </tfoot>
                            @endif
                        </table>
                    </div>

                    <div class="d-flex justify-content-end">
                        <a href="{{ route('consignments.billing.index') }}" class="btn btn-secondary mr-2">Batal</a>
                        <button type="submit" class="btn btn-success" {{ !$preview['valid'] ? 'disabled' : '' }}>
                            <i class="bi bi-check-circle"></i> Confirm & Generate Purchase / Payable
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    @push('scripts')
    <script>
        document.addEventListener('DOMContentLoaded', function () {
            const invoiceDateInput = document.getElementById('invoice_date');
            const paymentTermSelect = document.getElementById('payment_term_id');
            const dueDateInput = document.getElementById('due_date');

            function updateDueDate() {
                const selectedOption = paymentTermSelect.options[paymentTermSelect.selectedIndex];
                if (!selectedOption || !selectedOption.value) return;

                const longevity = parseInt(selectedOption.getAttribute('data-longevity') || '0', 10);
                const invoiceDateVal = invoiceDateInput.value;
                if (!invoiceDateVal) return;

                const d = new Date(invoiceDateVal);
                if (isNaN(d.getTime())) return;

                d.setDate(d.getDate() + longevity);
                const yyyy = d.getFullYear();
                const mm = String(d.getMonth() + 1).padStart(2, '0');
                const dd = String(d.getDate()).padStart(2, '0');
                dueDateInput.value = `${yyyy}-${mm}-${dd}`;
            }

            // Payment terms are a small bounded list rendered inline with a local
            // Select2. Select2 dispatches a jQuery change event, which a native
            // addEventListener would not receive, so bind through jQuery instead.
            $(paymentTermSelect).select2({
                width: '100%',
                allowClear: true,
                placeholder: '-- Pilih Termin Pembayaran --'
            }).on('change', updateDueDate);
            invoiceDateInput.addEventListener('change', function () {
                if (paymentTermSelect.value && !dueDateInput.value) {
                    updateDueDate();
                }
            });
        });
    </script>
    @endpush
@endsection

@extends('layouts.app')

@section('title', 'Buat Pembayaran POS Global')

@section('breadcrumb')
    <ol class="breadcrumb border-0 m-0">
        <li class="breadcrumb-item"><a href="{{ route('home') }}">Beranda</a></li>
        <li class="breadcrumb-item"><a href="{{ route('pos.global-payments.index') }}">Pembayaran POS Global</a></li>
        <li class="breadcrumb-item active">Buat Pembayaran</li>
    </ol>
@endsection

@section('content')
    <div class="container-fluid">
        <form id="pos-payment-form" action="{{ route('pos.global-payments.store', $startingTransaction->id) }}" method="POST">
            @csrf
            <div class="row">
                <div class="col-lg-12">
                    @include('utils.alerts')
                    <div class="form-group mb-3">
                        <button type="submit" class="btn btn-primary" id="btn-submit">Buat Pembayaran <i class="bi bi-check"></i></button>
                        <a href="{{ route('pos.global-payments.index') }}" class="btn btn-secondary">Batal</a>
                    </div>
                </div>

                <div class="col-lg-12">
                    <div class="card mb-3">
                        <div class="card-header bg-primary text-white">
                            <h5 class="mb-0">Informasi Pembayaran Multi-POS: {{ $customer->customer_name ?? 'Pelanggan POS' }}</h5>
                        </div>
                        <div class="card-body">
                            <div class="form-row row">
                                <div class="col-lg-6">
                                    <div class="form-group mb-3">
                                        <label for="customer_name">Pelanggan <span class="text-danger">*</span></label>
                                        <input type="text" class="form-control" name="customer_name" readonly
                                               value="{{ $customer->customer_name }} ({{ $customer->customer_phone ?? 'Tanpa No HP' }})">
                                    </div>
                                </div>
                                <div class="col-lg-6">
                                    <div class="form-group mb-3">
                                        <label for="date">Tanggal Pembayaran <span class="text-danger">*</span></label>
                                        <input type="date" class="form-control" name="date" required
                                               value="{{ now()->format('Y-m-d') }}">
                                    </div>
                                </div>
                            </div>

                            <div class="form-row row">
                                <div class="col-lg-4">
                                    <div class="form-group mb-3">
                                        <label for="reference">Referensi Pembayaran <span class="text-danger">*</span></label>
                                        <input type="text" class="form-control" name="reference" required
                                               value="{{ old('reference', 'GPP-' . date('Ymd-His')) }}" placeholder="Referensi pembayaran">
                                    </div>
                                </div>
                                <div class="col-lg-4">
                                    <div class="form-group mb-3">
                                        <label for="payment_method_id">Metode Pembayaran <span class="text-danger">*</span></label>
                                        <select name="payment_method_id" id="payment_method_id" class="form-control" required>
                                            <option value="">-- Pilih Metode Pembayaran --</option>
                                            @foreach($paymentMethods as $method)
                                                <option value="{{ $method->id }}" @selected(old('payment_method_id') == $method->id)>
                                                    {{ $method->name }}
                                                </option>
                                            @endforeach
                                        </select>
                                    </div>
                                </div>
                                <div class="col-lg-4">
                                    <div class="form-group mb-3">
                                        <label for="note">Catatan</label>
                                        <input type="text" class="form-control" name="note"
                                               value="{{ old('note') }}" placeholder="Catatan pembayaran">
                                    </div>
                                </div>
                            </div>

                            <div class="form-group mb-3">
                                <label for="attachment">Unggah Lampiran Bukti Pembayaran (PDF/Gambar)</label>
                                <div class="dropzone d-flex flex-wrap align-items-center justify-content-center" id="file-dropzone">
                                    <div class="dz-message" data-dz-message>
                                        <i class="bi bi-cloud-arrow-up"></i> Drag & Drop berkas di sini atau klik untuk unggah
                                    </div>
                                </div>
                                <input type="hidden" name="attachment" id="attachment">
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Allocations Table -->
                <div class="col-lg-12">
                    <div class="card mb-3">
                        <div class="card-header d-flex justify-content-between align-items-center">
                            <h5 class="mb-0">Alokasi Pembayaran Transaksi POS</h5>
                            <button type="button" class="btn btn-sm btn-outline-info" id="btn-preview">
                                <i class="bi bi-eye"></i> Pratinjau Alokasi ke Penjualan
                            </button>
                        </div>
                        <div class="card-body">
                            <p class="text-muted small">
                                Pembayaran dialokasikan pada tingkat transaksi POS. Sistem akan secara otomatis memprioritaskan penjualan internal yang reachable sesuai metode pembayaran.
                            </p>
                            <div class="table-responsive">
                                <table class="table table-bordered table-striped" id="pos-allocations-table">
                                    <thead class="table-light">
                                        <tr>
                                            <th>Kode Transaksi</th>
                                            <th>Cabang / Setting</th>
                                            <th>Tanggal</th>
                                            <th class="text-end">Total Nilai POS</th>
                                            <th class="text-end">Telah Dibayar</th>
                                            <th class="text-end">Sisa Tagihan (Live Due)</th>
                                            <th class="text-end" style="width: 200px;">Jumlah Alokasi (Rp)</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        @foreach($candidateTransactions as $candidate)
                                            @php
                                                $isStarting = $candidate->id === $startingTransaction->id;
                                                $defaultAmount = $isStarting ? $candidate->projection['live_due'] : 0;
                                            @endphp
                                            <tr data-transaction-id="{{ $candidate->id }}"
                                                data-live-due="{{ $candidate->projection['live_due'] }}"
                                                class="{{ $isStarting ? 'table-warning' : '' }}">
                                                <td>
                                                    <strong>{{ $candidate->code }}</strong>
                                                    @if($isStarting)
                                                        <span class="badge bg-primary ms-1">Mulai</span>
                                                    @endif
                                                </td>
                                                <td>{{ $candidate->setting->company_name ?? 'N/A' }}</td>
                                                <td>{{ \Carbon\Carbon::parse($candidate->created_at)->format('d/m/Y H:i') }}</td>
                                                <td class="text-end">{{ format_currency($candidate->projection['total_amount']) }}</td>
                                                <td class="text-end text-success">{{ format_currency($candidate->projection['effective_paid']) }}</td>
                                                <td class="text-end text-danger fw-bold">{{ format_currency($candidate->projection['live_due']) }}</td>
                                                <td class="text-end">
                                                    <input type="text"
                                                           name="allocations[{{ $candidate->id }}]"
                                                           class="form-control pos-allocation-input text-end"
                                                           data-payment-amount
                                                           value="{{ old('allocations.' . $candidate->id, $defaultAmount) }}"
                                                           data-max="{{ $candidate->projection['live_due'] }}"
                                                           placeholder="0,00">
                                                </td>
                                            </tr>
                                        @endforeach
                                    </tbody>
                                    <tfoot>
                                        <tr class="table-primary fw-bold">
                                            <td colspan="6" class="text-end">Total Pembayaran POS:</td>
                                            <td class="text-end"><span id="total-pos-allocation">{{ format_currency(0) }}</span></td>
                                        </tr>
                                    </tfoot>
                                </table>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Preview Modal / Expansion Container -->
                <div class="col-lg-12" id="preview-container" style="display: none;">
                    <div class="card border-info mb-3">
                        <div class="card-header bg-info text-white">
                            <h5 class="mb-0"><i class="bi bi-diagram-3"></i> Pratinjau Alokasi ke Penjualan Reachable (Sales)</h5>
                        </div>
                        <div class="card-body">
                            <div id="preview-content"></div>
                        </div>
                    </div>
                </div>

                <div class="col-lg-12">
                    <div class="form-group mb-4">
                        <button type="submit" class="btn btn-primary">Buat Pembayaran <i class="bi bi-check"></i></button>
                        <a href="{{ route('pos.global-payments.index') }}" class="btn btn-secondary">Batal</a>
                    </div>
                </div>
            </div>
        </form>
    </div>
@endsection

@push('page_scripts')
    <script src="{{ asset('js/payment-amount-input.js') }}"></script>
    <script src="{{ asset('js/dropzone.js') }}"></script>
    <script>
        Dropzone.autoDiscover = false;

        $(document).ready(function () {
            function formatNumber(num) {
                return 'Rp ' + PaymentAmountInput.formatDisplay(String(num || 0));
            }

            function escapeHtml(value) {
                return $('<div>').text(value === null || value === undefined ? '' : String(value)).html();
            }

            function updateTotalPosAllocation() {
                var total = 0;
                $('.pos-allocation-input').each(function () {
                    var canonical = PaymentAmountInput.getCanonicalValue(this);
                    total += canonical === null ? 0 : (parseFloat(canonical) || 0);
                });
                $('#total-pos-allocation').text(formatNumber(total));
            }

            // 'financial-amount:change' is the formatter's own event, dispatched after every
            // accepted real-time edit (typing, paste, Backspace/Delete); native 'input'/'keyup'
            // do not reliably cover every acceptance path (e.g. mouse-driven paste).
            $(document).on('change keyup blur financial-amount:change', '.pos-allocation-input', function () {
                updateTotalPosAllocation();
            });

            updateTotalPosAllocation();

            // Preview handler
            $('#btn-preview').on('click', function () {
                var methodId = $('#payment_method_id').val();
                if (!methodId) {
                    alert('Silakan pilih metode pembayaran terlebih dahulu.');
                    return;
                }

                if (!PaymentAmountInput.validateScope('.pos-allocation-input')) {
                    alert('Terdapat nominal alokasi yang tidak valid. Perbaiki sebelum melihat pratinjau.');
                    return;
                }

                var allocations = {};
                var hasPositive = false;
                $('.pos-allocation-input').each(function () {
                    var trxId = $(this).closest('tr').data('transaction-id');
                    var val = parseFloat(PaymentAmountInput.getCanonicalValue(this)) || 0;
                    if (val > 0) {
                        allocations[trxId] = val;
                        hasPositive = true;
                    }
                });

                if (!hasPositive) {
                    alert('Minimal satu transaksi POS harus memiliki nominal alokasi.');
                    return;
                }

                $('#preview-content').html('<div class="text-center py-3"><div class="spinner-border text-primary" role="status"></div><p class="mt-2 mb-0">Menghitung pratinjau alokasi...</p></div>');
                $('#preview-container').show();

                $.ajax({
                    url: '{{ route('pos.global-payments.preview', $startingTransaction->id) }}',
                    type: 'POST',
                    data: {
                        _token: '{{ csrf_token() }}',
                        payment_method_id: methodId,
                        allocations: allocations
                    },
                    success: function (res) {
                        if (res.success && res.data && Array.isArray(res.data.transactions)) {
                            var paymentMethodName = $('#payment_method_id option:selected').text().trim() || '-';

                            var html = '<div class="table-responsive"><table class="table table-sm table-bordered"><thead class="table-light"><tr><th>Kode POS</th><th>Ref Penjualan</th><th>Perusahaan</th><th>Metode Pembayaran</th><th class="text-end">Nominal Dialokasikan</th></tr></thead><tbody>';
                            var grandSum = 0;
                            var rowCount = 0;

                            res.data.transactions.forEach(function (transaction) {
                                var allocations = Array.isArray(transaction.planned_allocations) ? transaction.planned_allocations : [];
                                allocations.forEach(function (allocation) {
                                    var amount = parseFloat(allocation.allocated_amount) || 0;
                                    if (amount <= 0) {
                                        return;
                                    }
                                    rowCount++;
                                    grandSum += amount;
                                    html += '<tr>' +
                                        '<td>' + escapeHtml(transaction.code || '-') + '</td>' +
                                        '<td><strong>' + escapeHtml(allocation.sale_reference || '-') + '</strong></td>' +
                                        '<td>' + escapeHtml(allocation.setting_name || '-') + '</td>' +
                                        '<td>' + escapeHtml(paymentMethodName) + '</td>' +
                                        '<td class="text-end fw-bold">' + formatNumber(amount) + '</td>' +
                                        '</tr>';
                                });
                            });

                            if (rowCount === 0) {
                                html += '<tr><td colspan="5" class="text-center text-muted">Tidak ada alokasi yang direncanakan.</td></tr>';
                            }

                            html += '</tbody><tfoot><tr class="table-info fw-bold"><td colspan="4" class="text-end">Total Alokasi Efektif</td><td class="text-end">' + formatNumber(res.data.total_planned !== undefined ? res.data.total_planned : grandSum) + '</td></tr></tfoot></table></div>';
                            $('#preview-content').html(html);
                        } else {
                            $('#preview-content').html('<div class="alert alert-danger mb-0">Gagal memuat pratinjau.</div>');
                        }
                    },
                    error: function (xhr) {
                        var msg = (xhr.responseJSON && xhr.responseJSON.message) ? xhr.responseJSON.message : 'Terjadi kesalahan saat memuat pratinjau.';
                        $('#preview-content').html('<div class="alert alert-danger mb-0">' + msg + '</div>');
                    }
                });
            });

            // Dropzone configuration
            if (typeof Dropzone !== 'undefined' && $('#file-dropzone').length) {
                var myDropzone = new Dropzone('#file-dropzone', {
                    url: '{{ route('dropzone.upload.documents') }}',
                    maxFiles: 1,
                    acceptedFiles: '.pdf,.jpg,.jpeg,.png',
                    maxFilesize: 10,
                    addRemoveLinks: true,
                    headers: {
                        'X-CSRF-TOKEN': $('meta[name="csrf-token"]').attr('content')
                    },
                    init: function () {
                        this.on('success', function (file, response) {
                            file._serverName = response.name;
                            $('#attachment').val(response.name);
                        });

                        this.on('removedfile', function (file) {
                            var fileName = file._serverName || $('#attachment').val();
                            if (fileName) {
                                $.ajax({
                                    type: 'POST',
                                    url: '{{ route('dropzone.delete') }}',
                                    data: {
                                        _token: '{{ csrf_token() }}',
                                        file_name: fileName
                                    }
                                });
                            }
                            $('#attachment').val('');
                        });

                        this.on('addedfile', function () {
                            if (this.files.length > 1) {
                                this.removeFile(this.files[0]);
                            }
                        });
                    }
                });
            }

            // Form validation
            $('#pos-payment-form').on('submit', function (e) {
                if (!PaymentAmountInput.validateScope('.pos-allocation-input')) {
                    e.preventDefault();
                    alert('Terdapat nominal alokasi yang tidak valid. Perbaiki sebelum menyimpan.');
                    return false;
                }

                var hasAllocation = false;
                $('.pos-allocation-input').each(function () {
                    var canonical = parseFloat(PaymentAmountInput.getCanonicalValue(this)) || 0;
                    if (canonical > 0) {
                        hasAllocation = true;
                    }
                    $(this).val(PaymentAmountInput.getCanonicalValue(this));
                });

                if (!hasAllocation) {
                    e.preventDefault();
                    alert('Minimal satu transaksi POS harus memiliki alokasi pembayaran yang positif.');
                    return false;
                }
            });
        });
    </script>
@endpush

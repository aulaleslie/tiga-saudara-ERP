@extends('layouts.app')

@section('title', 'Buat Pembayaran')

@section('content')
    <div class="container-fluid">
        <form id="payment-form" action="{{ route('purchase-payments.store') }}" method="POST">
            @csrf
            <div class="row">
                <div class="col-lg-12">
                    @include('utils.alerts')
                    <div class="form-group">
                        <button class="btn btn-primary">Buat Pembayaran <i class="bi bi-check"></i></button>
                    </div>
                </div>
                <div class="col-lg-12">
                    <div class="card">
                        <div class="card-body">
                            @if($purchase->isConsignmentBilling() && $purchase->consignmentBillingConfirmation)
                                <div class="alert alert-info border-left-info shadow-sm mb-3">
                                    <i class="bi bi-link-45deg mr-1"></i>
                                    <strong>Tagihan Konsinyasi:</strong>
                                    Dikonversi dari Konfirmasi Alokasi
                                    <a href="{{ route('consignments.confirmations.show', $purchase->consignmentBillingConfirmation->id) }}" class="font-weight-bold alert-link">
                                        #{{ $purchase->consignmentBillingConfirmation->confirmation_number }}
                                    </a>
                                    @if($purchase->consignmentBillingConfirmation->supplier_invoice_number)
                                        &middot; Faktur Pemasok: {{ $purchase->consignmentBillingConfirmation->supplier_invoice_number }}
                                    @endif
                                    &middot; Nilai komersial bersifat read-only; pembayaran tetap dapat dicatat.
                                </div>
                            @endif
                            <div class="form-row">
                                <div class="col-lg-6">
                                    <div class="form-group">
                                        <label for="reference">Referensi <span class="text-danger">*</span></label>
                                        <input type="text" class="form-control" name="reference" required readonly
                                               value="INV/{{ $purchase->reference }}">
                                    </div>
                                </div>
                                <div class="col-lg-6">
                                    <div class="form-group">
                                        <label for="date">Tanggal <span class="text-danger">*</span></label>
                                        <input type="date" class="form-control" name="date" required
                                               value="{{ now()->format('Y-m-d') }}">
                                    </div>
                                </div>
                            </div>

                            <div class="form-row">
                                <div class="col-lg-4">
                                    <div class="form-group">
                                        <label for="due_amount">Jumlah yang Perlu Dibayar <span class="text-danger">*</span></label>
                                        <input type="text" class="form-control" name="due_amount" required
                                               value="{{ format_currency($purchase->due_amount) }}" readonly>
                                    </div>
                                </div>
                                <div class="col-lg-4">
                                    <div class="form-group">
                                        <label for="amount">Jumlah yang Dibayar <span class="text-danger">*</span></label>
                                        <input id="amount" type="text" class="form-control" name="amount" required
                                               data-payment-amount
                                               value="{{ old('amount') }}">
                                    </div>
                                </div>
                                <div class="col-lg-4">
                                    <div class="form-group">
                                        <label for="payment_method_id">Metode Pembayaran <span class="text-danger">*</span></label>
                                        <select id="payment_method_id" name="payment_method_id" class="form-control" required>
                                            <option value="">{{ __('Pilih metode…') }}</option>
                                            @foreach ($payment_methods as $pm)
                                                <option value="{{ $pm->id }}" {{ old('payment_method_id') == $pm->id ? 'selected' : '' }}>
                                                    {{ $pm->name }}
                                                </option>
                                            @endforeach
                                        </select>
                                        @error('payment_method_id')
                                        <small class="text-danger d-block">{{ $message }}</small>
                                        @enderror
                                    </div>
                                </div>
                            </div>

                            <div class="form-group">
                                <label for="note">Note</label>
                                <textarea class="form-control" rows="4" name="note">{{ old('note') }}</textarea>
                            </div>

                            <div class="form-group">
                                <label for="attachments">Unggah Berkas Lampiran (Gambar / PDF / Word / Excel / Teks)</label>
                                <div class="dropzone d-flex flex-wrap align-items-center justify-content-center"
                                     id="file-dropzone">
                                    <div class="dz-message" data-dz-message>
                                        <i class="bi bi-cloud-arrow-up"></i> Tarik & lepas berkas di sini atau klik untuk mengunggah
                                    </div>
                                </div>
                                <div id="attachments-hidden-inputs"></div>
                                <small class="form-text text-muted">
                                    Mendukung banyak berkas (JPG, PNG, WebP, GIF, BMP, PDF, DOC, DOCX, XLS, XLSX, TXT).
                                </small>
                            </div>

                            <input type="hidden" value="{{ $purchase->id }}" name="purchase_id">
                        </div>
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
            var paymentDropzone = new Dropzone('#file-dropzone', {
                url: '{{ route('purchase-payments.attachments.upload') }}',
                paramName: 'file',
                uploadMultiple: false,
                maxFilesize: null, // No application size cap; delegated to server/proxy transport limits
                timeout: 0,
                acceptedFiles: '.jpg,.jpeg,.png,.webp,.gif,.bmp,.pdf,.doc,.docx,.xls,.xlsx,.txt',
                addRemoveLinks: true,
                dictRemoveFile: "<i class='bi bi-x-circle text-danger'></i> Hapus",
                dictInvalidFileType: "Tipe berkas tidak didukung.",
                headers: {
                    'X-CSRF-TOKEN': "{{ csrf_token() }}"
                },
                init: function () {
                    // On successful upload, create unique hidden input for this file
                    this.on("success", function (file, response) {
                        file.serverFileName = response.name;
                        var input = $('<input>')
                            .attr('type', 'hidden')
                            .attr('name', 'attachments[]')
                            .attr('value', response.name)
                            .attr('data-client-name', file.name);
                        $('#attachments-hidden-inputs').append(input);
                    });

                    // On removing a file, remove only its corresponding hidden input and delete staged temp file
                    this.on("removedfile", function (file) {
                        var serverName = file.serverFileName;
                        if (serverName) {
                            $('#attachments-hidden-inputs')
                                .find('input[name="attachments[]"][value="' + serverName + '"]')
                                .remove();

                            $.ajax({
                                type: 'POST',
                                url: '{{ route('purchase-payments.attachments.delete') }}',
                                data: {
                                    _token: "{{ csrf_token() }}",
                                    file_name: serverName
                                }
                            });
                        } else {
                            // In case it was removed before upload finished
                            $('#attachments-hidden-inputs')
                                .find('input[data-client-name="' + file.name + '"]')
                                .remove();
                        }
                    });

                    this.on("error", function (file, message) {
                        var errorText = typeof message === 'object' && message.message ? message.message : message;
                        console.error('Upload error:', errorText);
                    });
                }
            });

            // Form submission logic
            $('#payment-form').on('submit', function (e) {
                if (paymentDropzone.getUploadingFiles().length > 0 || paymentDropzone.getQueuedFiles().length > 0) {
                    e.preventDefault();
                    alert('Mohon tunggu hingga proses unggah berkas selesai.');
                    return false;
                }

                var canonical = PaymentAmountInput.getCanonicalValue('#amount');
                if (canonical === null) {
                    e.preventDefault();
                    $('#amount').addClass(PaymentAmountInput.INVALID_CLASS).trigger('focus');
                    return false;
                }
                $('#amount').val(canonical);
            });
        });
    </script>
@endpush

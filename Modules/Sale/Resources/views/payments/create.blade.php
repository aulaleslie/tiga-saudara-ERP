@extends('layouts.app')

@section('title', 'Buat Pembayaran')

@section('breadcrumb')
    <ol class="breadcrumb border-0 m-0">
        <li class="breadcrumb-item"><a href="{{ route('home') }}">Beranda</a></li>
        <li class="breadcrumb-item"><a href="{{ route('sales.index') }}">Penjualan</a></li>
        <li class="breadcrumb-item"><a href="{{ route('sales.show', $sale) }}">{{ $sale->reference }}</a></li>
        <li class="breadcrumb-item active">Buat Pembayaran</li>
    </ol>
@endsection

@section('content')
    <div class="container-fluid">
        <form id="payment-form" action="{{ route('sale-payments.store') }}" method="POST">
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
                            <div class="form-row">
                                <div class="col-lg-6">
                                    <div class="form-group">
                                        <label for="reference">Referensi <span class="text-danger">*</span></label>
                                        <input type="text" class="form-control" name="reference" required readonly
                                               value="INV/{{ $sale->reference }}">
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
                                        <label for="due_amount">Jumlah yang Harus Dibayar <span class="text-danger">*</span></label>
                                        <input type="text" class="form-control" name="due_amount" required
                                               value="{{ format_currency($sale->due_amount) }}" readonly>
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
                                    <x-select label="Metode Pembayaran" name="payment_method_id"
                                              :options="$payment_methods->pluck('name', 'id')"/>
                                </div>
                            </div>

                            @if(isset($customerCredits) && $customerCredits->isNotEmpty())
                                <div class="card mb-3 border shadow-sm">
                                    <div class="card-header bg-light">
                                        <strong>Gunakan Kredit Pelanggan</strong>
                                    </div>
                                    <div class="card-body">
                                        <div class="form-row">
                                            <div class="col-lg-6">
                                                <div class="form-group mb-0">
                                                    <label for="credit_customer_credit_id">Pilih Kredit</label>
                                                    <select name="credit_customer_credit_id" id="credit_customer_credit_id" class="form-control">
                                                        <option value="">Tidak menggunakan kredit</option>
                                                        @foreach($customerCredits as $credit)
                                                            <option value="{{ $credit->id }}" @selected(old('credit_customer_credit_id') == $credit->id) data-remaining="{{ $credit->remaining_amount }}">
                                                                {{ optional($credit->saleReturn)->reference ?? 'Retur' }} - Sisa {{ format_currency($credit->remaining_amount) }}
                                                            </option>
                                                        @endforeach
                                                    </select>
                                                </div>
                                            </div>
                                            <div class="col-lg-6">
                                                <div class="form-group mb-0">
                                                    <label for="credit_amount">Nominal Kredit yang Dipakai</label>
                                                    <input type="number" step="0.01" min="0" class="form-control" name="credit_amount" id="credit_amount" value="{{ old('credit_amount', 0) }}">
                                                    <small class="form-text text-muted">Maksimal sesuai saldo kredit terpilih.</small>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            @endif

                            <div class="form-group">
                                <label for="note">Catatan</label>
                                <textarea class="form-control" rows="4" name="note">{{ old('note') }}</textarea>
                            </div>

                            <div class="form-group">
                                <label for="attachment">Unggah Lampiran (PDF/Gambar)</label>
                                <div class="dropzone d-flex flex-wrap align-items-center justify-content-center" id="file-dropzone">
                                    <div class="dz-message" data-dz-message>
                                        <i class="bi bi-cloud-arrow-up"></i> Drag & Drop a file here or click to upload
                                    </div>
                                </div>
                            </div>

                            <input type="hidden" name="sale_id" value="{{ $sale->id }}">
                        </div>
                    </div>
                </div>
            </div>
        </form>
    </div>
@endsection

@push('page_scripts')
    <script src="{{ asset('js/payment-amount-input.js') }}"></script>
    <script>
        $(document).ready(function () {
            // On form submission, block on an invalid amount; otherwise submit the canonical value.
            $('#payment-form').on('submit', function (e) {
                var canonical = PaymentAmountInput.getCanonicalValue('#amount');
                if (canonical === null) {
                    e.preventDefault();
                    $('#amount').addClass(PaymentAmountInput.INVALID_CLASS).trigger('focus');
                    return false;
                }
                $('#amount').val(canonical);
            });

            var creditSelect = $('#credit_customer_credit_id');
            var creditAmountInput = $('#credit_amount');

            function syncCreditMax() {
                if (!creditSelect.length) {
                    return;
                }

                var remaining = parseFloat(creditSelect.find('option:selected').data('remaining'));

                if (!isNaN(remaining)) {
                    creditAmountInput.attr('max', remaining.toFixed(2));
                } else {
                    creditAmountInput.removeAttr('max');
                }
            }

            creditSelect.on('change', function () {
                syncCreditMax();

                if (!creditSelect.val()) {
                    creditAmountInput.val(0);
                }
            });

            syncCreditMax();
        });
    </script>

    <script src="{{ asset('js/dropzone.js') }}"></script>
    <script>
        Dropzone.options.fileDropzone = {
            url: '{{ route('dropzone.upload') }}', // Upload route
            maxFilesize: 2, // Maximum file size in MB
            acceptedFiles: '.jpg,.jpeg,.png,.pdf', // Allowed file types
            maxFiles: 1, // Only one file allowed
            addRemoveLinks: true,
            dictRemoveFile: "<i class='bi bi-x-circle text-danger'></i> Remove",
            headers: {
                'X-CSRF-TOKEN': "{{ csrf_token() }}"
            },
            init: function () {
                var uploadedFileMap = {};

                // Handle successful upload
                this.on("success", function (file, response) {
                    $('form').append('<input type="hidden" name="attachment" value="' + response.name + '">');
                    uploadedFileMap[file.name] = response.name;
                });

                // Handle removal of file
                this.on("removedfile", function (file) {
                    var name = uploadedFileMap[file.name] || file.name;
                    $.ajax({
                        type: 'POST',
                        url: '{{ route('dropzone.delete') }}',
                        data: {
                            _token: "{{ csrf_token() }}",
                            file_name: name
                        },
                    });
                    $('form').find('input[name="attachment"][value="' + name + '"]').remove();
                });

                // Ensure only one file is uploaded
                this.on("addedfile", function () {
                    if (this.files.length > 1) {
                        this.removeFile(this.files[0]);
                    }
                });

                // Generate thumbnails for images
                this.on("thumbnail", function (file, dataUrl) {
                    if (file.type.startsWith("image/")) {
                        this.emit("thumbnail", file, dataUrl);
                    }
                });
            }
        };
    </script>
@endpush

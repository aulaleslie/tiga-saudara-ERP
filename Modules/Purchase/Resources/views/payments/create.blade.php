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
                                               value="{{ old('amount') }}">
                                    </div>
                                </div>
                                <div class="col-lg-4">
                                    <x-select label="Metode Pembayaran" name="payment_method_id"
                                              :options="$payment_methods->pluck('name', 'id')"/>
                                </div>
                            </div>

                            <div class="form-group">
                                <label for="note">Note</label>
                                <textarea class="form-control" rows="4" name="note">{{ old('note') }}</textarea>
                            </div>

                            <div class="form-group">
                                <div class="form-group">
                                    <label for="attachment">Unggah Berkas (PDF/Gambar)</label>
                                    <div class="dropzone d-flex flex-wrap align-items-center justify-content-center"
                                         id="file-dropzone">
                                        <div class="dz-message" data-dz-message>
                                            <i class="bi bi-cloud-arrow-up"></i> Drag & Drop a file here or click to
                                            upload
                                        </div>
                                    </div>
                                </div>
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
    <script src="{{ asset('js/jquery-mask-money.js') }}"></script>
    <script>
        $(document).ready(function () {
            // Initialize maskMoney for the numeric fields
            function applyMask() {
                $('#amount').maskMoney({
                    prefix: '{{ settings()->currency->symbol }}',
                    thousands: '{{ settings()->currency->thousand_separator }}',
                    decimal: '{{ settings()->currency->decimal_separator }}',
                    precision: 2,
                    allowZero: true,
                    allowNegative: false
                });
            }

            applyMask();

            // On focus, unmask and allow editing the raw value
            $('#amount').on('focus', function () {
                $(this).maskMoney('destroy'); // Remove mask
                $(this).val($(this).val().replace(/[^0-9.-]/g, '')); // Show raw value
                setTimeout(() => {
                    $(this).select(); // Select all text
                }, 0);
            });

            // On blur, reapply the mask
            $('#amount').on('blur', function () {
                const value = parseFloat($(this).val().replace(/[^0-9.-]/g, ''));
                if (!isNaN(value)) {
                    $(this).val(value.toFixed(2)); // Format to 2 decimal places
                } else {
                    $(this).val(''); // Clear invalid input
                }
                applyMask(); // Reapply mask
                $(this).maskMoney('mask'); // Mask the value
            });

            // On form submission, unmask the value
            $('#payment-form').submit(function () {
                const rawValue = $('#amount').maskMoney('unmasked')[0];
                $('#amount').val(rawValue); // Set raw value for submission
            });
        });
    </script>

    <script src="{{ asset('js/dropzone.js') }}"></script>
    <script>
        Dropzone.options.fileDropzone = {
            url: '{{ route('dropzone.upload') }}', // Upload route
            maxFilesize: 2, // Maximum file size (in MB)
            acceptedFiles: '.jpg,.jpeg,.png,.pdf', // Only allow images and PDFs
            maxFiles: 1, // Restrict to one file
            addRemoveLinks: true, // Enable remove links
            dictRemoveFile: "<i class='bi bi-x-circle text-danger'></i> Remove",
            headers: {
                'X-CSRF-TOKEN': "{{ csrf_token() }}" // CSRF token for security
            },
            init: function () {
                var uploadedFileMap = {};

                // Preload existing file for edit mode
                @if(isset($payment) && $payment->getMedia('attachments'))
                var files = {!! json_encode($payment->getMedia('attachments')) !!};
                for (var i in files) {
                    var file = files[i];
                    this.options.addedfile.call(this, file);
                    this.options.thumbnail.call(this, file, file.original_url);
                    file.previewElement.classList.add('dz-complete');
                    $('form').append('<input type="hidden" name="attachment" value="' + file.file_name + '">');
                }
                @endif

                // Handle file upload success
                this.on("success", function (file, response) {
                    $('form').append('<input type="hidden" name="attachment" value="' + response.name + '">');
                    uploadedFileMap[file.name] = response.name;
                });

                // Handle file removal
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

                // Ensure only one file is uploaded at a time
                this.on("addedfile", function () {
                    if (this.files.length > 1) {
                        this.removeFile(this.files[0]); // Remove the previously uploaded file
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


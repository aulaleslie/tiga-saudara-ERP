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
            // Get currency settings from your Blade variables
            var currencySymbol = '{{ settings()->currency->symbol }}';
            var thousandsSeparator = '{{ settings()->currency->thousand_separator }}';
            var decimalSeparator = '{{ settings()->currency->decimal_separator }}';

            // A helper to format a number as currency
            function formatCurrency(num) {
                // Use toLocaleString to get proper formatting.
                // Adjust the locale or options as needed.
                var formatted = parseFloat(num).toLocaleString('en-US', {
                    minimumFractionDigits: 2,
                    maximumFractionDigits: 2
                });
                return currencySymbol + formatted;
            }

            // On focus: remove any currency formatting so the user sees a raw number.
            $('#amount').on('focus', function () {
                var val = $(this).val();
                // Remove currency symbol and thousands separators.
                // This regex assumes the currency symbol is a fixed string.
                var raw = val.replace(new RegExp('\\' + currencySymbol, 'g'), '')
                    .replace(new RegExp('\\' + thousandsSeparator, 'g'), '')
                    .trim();
                $(this).val(raw);
                $(this).select();
            });

            // On blur: validate and format the number as currency.
            $('#amount').on('blur', function () {
                var val = $(this).val();
                var num = parseFloat(val);
                if (!isNaN(num)) {
                    $(this).val(formatCurrency(num));
                } else {
                    $(this).val(''); // clear if invalid
                }
            });

            // On form submission, if you need to submit the raw number, you can strip formatting.
            $('#payment-form').on('submit', function () {
                var val = $('#amount').val();
                var raw = val.replace(new RegExp('\\' + currencySymbol, 'g'), '')
                    .replace(new RegExp('\\' + thousandsSeparator, 'g'), '')
                    .trim();
                $('#amount').val(raw);
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


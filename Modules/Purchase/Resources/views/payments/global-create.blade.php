@extends('layouts.app')

@section('title', 'Buat Pembayaran Global')

@section('content')
    <div class="container-fluid">
        <form id="payment-form" action="{{ route('purchases.global-payments.store', $supplier->id) }}" method="POST">
            @csrf
            <input type="hidden" name="idempotency_token" value="{{ \Illuminate\Support\Str::uuid() }}">
            <div class="row">
                <div class="col-lg-12">
                    @include('utils.alerts')
                    <div class="form-group d-flex justify-content-between">
                        <a href="{{ route('purchases.global-payments.index') }}" class="btn btn-secondary">Batal</a>
                        <button class="btn btn-primary" id="btn-submit">Simpan Pembayaran <i class="bi bi-check"></i></button>
                    </div>
                </div>
                <div class="col-lg-12">
                    <div class="card">
                        <div class="card-body">
                            <div class="form-row">
                                <div class="col-lg-4">
                                    <div class="form-group">
                                        <label>Pemasok</label>
                                        <input type="text" class="form-control" value="{{ $supplier->supplier_name }}" readonly>
                                    </div>
                                </div>
                                <div class="col-lg-4">
                                    <div class="form-group">
                                        <label for="reference">Referensi Pembayaran <span class="text-danger">*</span></label>
                                        <input type="text" class="form-control" name="reference" required value="{{ old('reference') }}">
                                    </div>
                                </div>
                                <div class="col-lg-4">
                                    <div class="form-group">
                                        <label for="date">Tanggal <span class="text-danger">*</span></label>
                                        <input type="date" class="form-control" name="date" required value="{{ old('date', now()->format('Y-m-d')) }}">
                                    </div>
                                </div>
                            </div>

                            <div class="form-row">
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
                                <div class="col-lg-4">
                                    <div class="form-group">
                                        <label>Total Alokasi Pembayaran</label>
                                        <input type="text" class="form-control font-weight-bold" id="total_allocation_display" readonly value="0">
                                    </div>
                                </div>
                            </div>

                            <hr>

                            <h5>Alokasi Faktur</h5>
                            <div class="table-responsive">
                                <table class="table table-bordered" id="allocations-table">
                                    <thead>
                                        <tr>
                                            <th>Nomor Transaksi</th>
                                            <th>No. Pembelian Supplier</th>
                                            <th>Deskripsi</th>
                                            <th>Jatuh Tempo</th>
                                            <th>Total</th>
                                            <th>Sisa Tagihan</th>
                                            <th style="width: 250px;">Jumlah Dibayar</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        @foreach($candidates as $candidate)
                                        @php
                                            $isStarting = $startingPurchase && $startingPurchase->id === $candidate->id;
                                            $defaultAmount = $isStarting ? $candidate->live_due_amount : 0;
                                            $oldAmount = old('allocations.'.$candidate->id, $defaultAmount);
                                        @endphp
                                        <tr>
                                            <td>
                                                <a href="{{ route('purchases.global-payments.show', $candidate->id) }}" target="_blank">
                                                    {{ $candidate->reference }}
                                                </a>
                                                @if(filled($candidate->note))
                                                    <br><small class="text-muted d-block text-wrap">{{ $candidate->note }}</small>
                                                @endif
                                            </td>
                                            <td>{{ $candidate->supplier_purchase_number ?? '-' }}</td>
                                            <td>Pembelian</td>
                                            <td>{{ $candidate->due_date ? \Carbon\Carbon::parse($candidate->due_date)->format('d M Y') : '-' }}</td>
                                            <td>{{ format_currency($candidate->total_amount) }}</td>
                                            <td>{{ format_currency($candidate->live_due_amount) }}</td>
                                            <td>
                                                <input type="text" class="form-control allocation-input" 
                                                    data-id="{{ $candidate->id }}"
                                                    data-max="{{ $candidate->live_due_amount }}"
                                                    value="{{ $oldAmount }}">
                                                <input type="hidden" name="allocations[{{ $candidate->id }}]" id="allocation_hidden_{{ $candidate->id }}" value="{{ $oldAmount }}">
                                            </td>
                                        </tr>
                                        @endforeach
                                    </tbody>
                                </table>
                            </div>

                            <div class="form-group mt-3">
                                <label for="note">Note</label>
                                <textarea class="form-control" rows="4" name="note">{{ old('note') }}</textarea>
                            </div>

                            <div class="form-group">
                                <div class="form-group">
                                    <label for="attachment">Unggah Berkas (PDF/Gambar)</label>
                                    <div class="dropzone d-flex flex-wrap align-items-center justify-content-center"
                                         id="file-dropzone">
                                        <div class="dz-message" data-dz-message>
                                            <i class="bi bi-cloud-arrow-up"></i> Drag & Drop a file here or click to upload
                                        </div>
                                    </div>
                                </div>
                            </div>
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
            var currencySymbol = '{{ settings()->currency->symbol }}';
            var thousandsSeparator = '{{ settings()->currency->thousand_separator }}';
            var decimalSeparator = '{{ settings()->currency->decimal_separator }}';

            function formatCurrency(num) {
                var parts = parseFloat(num || 0).toFixed(2).split('.');
                parts[0] = parts[0].replace(/\B(?=(\d{3})+(?!\d))/g, thousandsSeparator);
                return currencySymbol + parts.join(decimalSeparator);
            }

            function parseCurrency(val) {
                if (!val) return 0;
                var raw = val.toString().replace(new RegExp('\\' + currencySymbol, 'g'), '')
                    .replace(new RegExp('\\' + thousandsSeparator, 'g'), '')
                    .replace(new RegExp('\\' + decimalSeparator, 'g'), '.')
                    .trim();
                var num = parseFloat(raw);
                return isNaN(num) ? 0 : num;
            }

            var table = $('#allocations-table').DataTable({
                lengthMenu: [[10, 25, 50, 100, -1], [10, 25, 50, 100, "All"]],
                pageLength: 10,
                ordering: false,
            });

            function recalculateTotal() {
                var total = 0;
                table.$('.allocation-input').each(function() {
                    total += parseCurrency($(this).val());
                });
                $('#total_allocation_display').val(formatCurrency(total));
            }

            table.$('.allocation-input').each(function() {
                var val = $(this).val();
                $(this).val(formatCurrency(val));
            });
            recalculateTotal();

            $('#allocations-table').on('focus', '.allocation-input', function () {
                var val = $(this).val();
                var raw = parseCurrency(val);
                if (raw === 0) {
                    $(this).val('');
                } else {
                    $(this).val(raw);
                }
                $(this).select();
            });

            $('#allocations-table').on('blur', '.allocation-input', function () {
                var val = $(this).val();
                var num = parseCurrency(val);
                var max = parseFloat($(this).data('max'));
                
                if (num > max) {
                    num = max;
                }
                
                $(this).val(formatCurrency(num));
                
                var id = $(this).data('id');
                $('#allocation_hidden_' + id).val(num);
                
                recalculateTotal();
            });

            $('#payment-form').on('submit', function (e) {
                // Ensure all inputs are synced to hidden fields before submit
                // And append hidden inputs to the form since DataTables removes off-page rows
                table.$('.allocation-input').each(function() {
                    var id = $(this).data('id');
                    var val = parseCurrency($(this).val());
                    
                    // If the hidden input is not in the DOM, it might have been removed by DataTables pagination
                    // DataTables removes tr elements from DOM when they are not on the current page.
                    // We need to add the hidden inputs back to the form if they are not present.
                    if ($('#allocation_hidden_' + id).length === 0 || !$.contains(document, $('#allocation_hidden_' + id)[0])) {
                        $('<input>').attr({
                            type: 'hidden',
                            name: 'allocations[' + id + ']',
                            id: 'allocation_hidden_form_' + id,
                            value: val
                        }).appendTo('#payment-form');
                    } else {
                        $('#allocation_hidden_' + id).val(val);
                    }
                });
                
                $('#btn-submit').attr('disabled', true);
            });
        });
    </script>

    <script src="{{ asset('js/dropzone.js') }}"></script>
    <script>
        Dropzone.options.fileDropzone = {
            url: '{{ route('dropzone.upload') }}',
            maxFilesize: 2,
            acceptedFiles: '.jpg,.jpeg,.png,.pdf',
            maxFiles: 1,
            addRemoveLinks: true,
            dictRemoveFile: "<i class='bi bi-x-circle text-danger'></i> Remove",
            headers: {
                'X-CSRF-TOKEN': "{{ csrf_token() }}"
            },
            init: function () {
                var uploadedFileMap = {};

                this.on("success", function (file, response) {
                    $('form').append('<input type="hidden" name="attachment" value="' + response.name + '">');
                    uploadedFileMap[file.name] = response.name;
                });

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

                this.on("addedfile", function () {
                    if (this.files.length > 1) {
                        this.removeFile(this.files[0]);
                    }
                });
            }
        };
    </script>
@endpush

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
                                            <th style="width: 220px;">Jumlah Dibayar</th>
                                            <th style="width: 260px;">Lampiran</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        @foreach($candidates as $candidate)
                                        @php
                                            $isStarting = $startingPurchase && $startingPurchase->id === $candidate->id;
                                            $defaultAmount = $isStarting ? $candidate->live_due_amount : 0;
                                            $oldAmount = old('allocations.'.$candidate->id, $defaultAmount);
                                            $oldAttachments = old('attachments.'.$candidate->id, []);
                                        @endphp
                                        <tr data-purchase-id="{{ $candidate->id }}">
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
                                                    data-payment-amount
                                                    data-id="{{ $candidate->id }}"
                                                    data-max="{{ $candidate->live_due_amount }}"
                                                    value="{{ $oldAmount }}">
                                                <input type="hidden" name="allocations[{{ $candidate->id }}]" id="allocation_hidden_{{ $candidate->id }}" value="{{ $oldAmount }}">
                                            </td>
                                            <td>
                                                <div class="row-dropzone dropzone p-2 border rounded"
                                                     id="dropzone-purchase-{{ $candidate->id }}"
                                                     data-purchase-id="{{ $candidate->id }}"
                                                     style="min-height: 80px; font-size: 0.85rem;">
                                                    <div class="dz-message m-1 text-center" data-dz-message style="font-size: 0.8rem; cursor: pointer;">
                                                        <i class="bi bi-cloud-arrow-up"></i> <span class="d-inline">Upload berkas</span>
                                                    </div>
                                                </div>
                                                <div class="row-attachments-hidden-inputs" id="attachments-hidden-inputs-{{ $candidate->id }}">
                                                    @if(is_array($oldAttachments))
                                                        @foreach($oldAttachments as $oldFile)
                                                            @if(!empty($oldFile))
                                                                <input type="hidden" name="attachments[{{ $candidate->id }}][]" value="{{ $oldFile }}" data-file-name="{{ $oldFile }}">
                                                            @endif
                                                        @endforeach
                                                    @endif
                                                </div>
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

                            <div id="persistent-attachments-container"></div>
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
            function formatCurrency(num) {
                return PaymentAmountInput.formatDisplay(String(num || 0));
            }

            // Global map to track staged attachments across all purchases (even when off-page)
            // Format: rowAttachmentsMap[purchaseId] = [ { serverName: '...', clientName: '...' } ]
            var rowAttachmentsMap = {};

            // Initialize rowAttachmentsMap with any old input values rendered on page load
            @foreach($candidates as $candidate)
                rowAttachmentsMap[{{ $candidate->id }}] = [];
                @php
                    $candidateOldAttachments = old('attachments.'.$candidate->id, []);
                @endphp
                @if(is_array($candidateOldAttachments))
                    @foreach($candidateOldAttachments as $oldFile)
                        @if(!empty($oldFile))
                            rowAttachmentsMap[{{ $candidate->id }}].push({
                                serverName: "{{ $oldFile }}",
                                clientName: "{{ $oldFile }}"
                            });
                        @endif
                    @endforeach
                @endif
            @endforeach

            var activeDropzones = {}; // Map of purchaseId => Dropzone instance for active table rows

            var table = $('#allocations-table').DataTable({
                lengthMenu: [[10, 25, 50, 100, -1], [10, 25, 50, 100, "All"]],
                pageLength: 10,
                ordering: false,
                drawCallback: function () {
                    initDropzonesForVisibleRows();
                }
            });

            function initDropzonesForVisibleRows() {
                $('#allocations-table tbody .row-dropzone').each(function () {
                    var el = this;
                    var purchaseId = $(el).data('purchase-id');

                    if (activeDropzones[purchaseId]) {
                        return; // already initialized
                    }

                    var dz = new Dropzone(el, {
                        url: '{{ route('purchase-payments.attachments.upload') }}',
                        paramName: 'file',
                        uploadMultiple: false,
                        maxFilesize: null,
                        timeout: 0,
                        acceptedFiles: '.jpg,.jpeg,.png,.webp,.gif,.bmp,.pdf,.doc,.docx,.xls,.xlsx,.txt',
                        addRemoveLinks: true,
                        dictRemoveFile: "<i class='bi bi-x-circle text-danger'></i>",
                        dictInvalidFileType: "Tipe berkas tidak didukung.",
                        headers: {
                            'X-CSRF-TOKEN': "{{ csrf_token() }}"
                        },
                        init: function () {
                            var self = this;

                            // Display pre-existing files if any (e.g. from old input)
                            if (rowAttachmentsMap[purchaseId] && rowAttachmentsMap[purchaseId].length > 0) {
                                $.each(rowAttachmentsMap[purchaseId], function (idx, item) {
                                    var mockFile = { name: item.clientName, size: 12345, serverFileName: item.serverName };
                                    self.emit("addedfile", mockFile);
                                    self.emit("complete", mockFile);
                                    self.files.push(mockFile);
                                });
                            }

                            self.on("success", function (file, response) {
                                file.serverFileName = response.name;
                                if (!rowAttachmentsMap[purchaseId]) {
                                    rowAttachmentsMap[purchaseId] = [];
                                }
                                rowAttachmentsMap[purchaseId].push({
                                    serverName: response.name,
                                    clientName: file.name
                                });
                            });

                            self.on("removedfile", function (file) {
                                var serverName = file.serverFileName;
                                if (serverName) {
                                    if (rowAttachmentsMap[purchaseId]) {
                                        rowAttachmentsMap[purchaseId] = rowAttachmentsMap[purchaseId].filter(function (f) {
                                            return f.serverName !== serverName;
                                        });
                                    }

                                    $.ajax({
                                        type: 'POST',
                                        url: '{{ route('purchase-payments.attachments.delete') }}',
                                        data: {
                                            _token: "{{ csrf_token() }}",
                                            file_name: serverName
                                        }
                                    });
                                } else {
                                    if (rowAttachmentsMap[purchaseId]) {
                                        rowAttachmentsMap[purchaseId] = rowAttachmentsMap[purchaseId].filter(function (f) {
                                            return f.clientName !== file.name;
                                        });
                                    }
                                }
                            });

                            self.on("error", function (file, message) {
                                var errorText = typeof message === 'object' && message.message ? message.message : message;
                                alert('Gagal mengunggah berkas untuk pembelian #' + purchaseId + ': ' + errorText + '\nHapus atau unggah ulang berkas yang gagal sebelum menyimpan.');
                            });
                        }
                    });

                    activeDropzones[purchaseId] = dz;
                });
            }

            function recalculateTotal() {
                var total = 0;
                table.$('.allocation-input').each(function() {
                    var canonical = PaymentAmountInput.getCanonicalValue(this);
                    total += canonical === null ? 0 : (parseFloat(canonical) || 0);
                });
                $('#total_allocation_display').val(formatCurrency(total));
            }

            table.$('.allocation-input').each(function () {
                PaymentAmountInput.enhance(this);
            });
            recalculateTotal();

            $('#allocations-table').on('financial-amount:change', '.allocation-input', function () {
                var id = $(this).data('id');
                var canonical = PaymentAmountInput.getCanonicalValue(this);

                if (canonical === null) {
                    recalculateTotal();
                    return;
                }

                $('#allocation_hidden_' + id).val(canonical);
                recalculateTotal();
            });

            $('#allocations-table').on('blur', '.allocation-input', function () {
                var id = $(this).data('id');
                var canonical = PaymentAmountInput.getCanonicalValue(this);

                if (canonical === null) {
                    recalculateTotal();
                    return;
                }

                var num = parseFloat(canonical) || 0;
                var max = parseFloat($(this).data('max'));

                if (!isNaN(max) && num > max) {
                    num = max;
                    PaymentAmountInput.setCanonicalValue(this, num);
                }

                $('#allocation_hidden_' + id).val(PaymentAmountInput.getCanonicalValue(this));
                recalculateTotal();
            });

            $('#payment-form').on('submit', function (e) {
                // 1. Check if any dropzone has ongoing uploads or failed files
                var uploadPending = false;
                var hasFailedUploads = false;
                $.each(activeDropzones, function (pId, dz) {
                    if (dz.getUploadingFiles().length > 0 || dz.getQueuedFiles().length > 0) {
                        uploadPending = true;
                        return false;
                    }
                    // Check for files in error status
                    var failed = dz.getFilesWithStatus(Dropzone.ERROR);
                    if (failed && failed.length > 0) {
                        hasFailedUploads = true;
                        return false;
                    }
                });

                if (uploadPending) {
                    e.preventDefault();
                    alert('Mohon tunggu hingga seluruh proses unggah berkas selesai.');
                    return false;
                }

                if (hasFailedUploads) {
                    e.preventDefault();
                    alert('Terdapat berkas yang gagal diunggah. Hapus atau unggah ulang berkas yang gagal sebelum menyimpan.');
                    return false;
                }

                // 2. Block submission if any allocation input holds an invalid edit.
                var hasInvalid = false;
                table.$('.allocation-input').each(function () {
                    if (PaymentAmountInput.getCanonicalValue(this) === null) {
                        $(this).addClass(PaymentAmountInput.INVALID_CLASS);
                        hasInvalid = true;
                    }
                });

                if (hasInvalid) {
                    e.preventDefault();
                    alert('Terdapat nominal alokasi yang tidak valid. Perbaiki sebelum menyimpan.');
                    return false;
                }

                // 3. Check for zero-amount rows having staged files
                var zeroAmountWithFiles = false;
                var zeroAmountPurchaseRef = '';

                // Sync all allocations across DataTables pages and check zero-allocation attachments
                table.$('.allocation-input').each(function() {
                    var id = $(this).data('id');
                    var canonical = PaymentAmountInput.getCanonicalValue(this);
                    var val = canonical === null ? 0 : (parseFloat(canonical) || 0);
                    var max = parseFloat($(this).data('max'));

                    if (!isNaN(max) && val > max) {
                        val = max;
                    }

                    var rowFiles = rowAttachmentsMap[id] || [];
                    if (val <= 0 && rowFiles.length > 0) {
                        zeroAmountWithFiles = true;
                        zeroAmountPurchaseRef = '#' + id;
                    }

                    // Attach hidden input to persistent form if off-page
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

                if (zeroAmountWithFiles) {
                    e.preventDefault();
                    alert('Baris alokasi dengan nominal 0 tidak boleh memiliki lampiran berkas.');
                    return false;
                }

                // 4. Serialize all attachments across all purchases into persistent container
                $('#persistent-attachments-container').empty();
                $.each(rowAttachmentsMap, function (purchaseId, files) {
                    if (files && files.length > 0) {
                        $.each(files, function (idx, fileObj) {
                            $('<input>').attr({
                                type: 'hidden',
                                name: 'attachments[' + purchaseId + '][]',
                                value: fileObj.serverName
                            }).appendTo('#persistent-attachments-container');
                        });
                    }
                });

                // Remove any inline attachment hidden inputs that were already in the table to avoid duplication
                $('.row-attachments-hidden-inputs').empty();

                $('#btn-submit').attr('disabled', true);
            });
        });
    </script>
@endpush

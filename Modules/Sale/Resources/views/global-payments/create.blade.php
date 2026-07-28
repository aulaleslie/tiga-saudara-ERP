@extends('layouts.app')

@section('title', 'Buat Pembayaran Penjualan Global')

@section('breadcrumb')
    <ol class="breadcrumb border-0 m-0">
        <li class="breadcrumb-item"><a href="{{ route('home') }}">Beranda</a></li>
        <li class="breadcrumb-item"><a href="{{ route('sales.global-payments.index') }}">Pembayaran Penjualan Global</a></li>
        <li class="breadcrumb-item active">Buat Pembayaran</li>
    </ol>
@endsection

@section('content')
    <div class="container-fluid">
        <form id="payment-form" action="{{ route('sales.global-payments.store', $startingSale->id) }}" method="POST">
            @csrf
            <div class="row">
                <div class="col-lg-12">
                    @include('utils.alerts')
                    <div class="form-group">
                        <button type="submit" class="btn btn-primary">Buat Pembayaran <i class="bi bi-check"></i></button>
                        <a href="{{ route('sales.global-payments.index') }}" class="btn btn-secondary">Batal</a>
                    </div>
                </div>
                <div class="col-lg-12">
                    <div class="card">
                        <div class="card-header">
                            <h5 class="mb-0">Pembayaran Multi Penjualan untuk {{ $startingSale->customer->customer_name }}</h5>
                        </div>
                        <div class="card-body">
                            <div class="form-row">
                                <div class="col-lg-6">
                                    <div class="form-group">
                                        <label for="customer_name">Pelanggan <span class="text-danger">*</span></label>
                                        <input type="text" class="form-control" name="customer_name" readonly
                                               value="{{ $startingSale->customer->customer_name }}">
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
                                        <label for="reference">Referensi <span class="text-danger">*</span></label>
                                        <input type="text" class="form-control" name="reference" required
                                               value="{{ old('reference') }}" placeholder="Referensi pembayaran">
                                    </div>
                                </div>
                                <div class="col-lg-4">
                                    <div class="form-group">
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
                                    <div class="form-group">
                                        <label for="note">Catatan</label>
                                        <input type="text" class="form-control" name="note"
                                               value="{{ old('note') }}" placeholder="Catatan pembayaran">
                                    </div>
                                </div>
                            </div>

                            <div class="form-group">
                                <label for="attachment">Unggah Lampiran (PDF/Gambar)</label>
                                <div class="dropzone d-flex flex-wrap align-items-center justify-content-center" id="file-dropzone">
                                    <div class="dz-message" data-dz-message>
                                        <i class="bi bi-cloud-arrow-up"></i> Drag & Drop a file here or click to upload
                                    </div>
                                </div>
                                <input type="hidden" name="attachment" id="attachment">
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Allocations Table -->
                <div class="col-lg-12">
                    <div class="card">
                        <div class="card-header">
                            <h5 class="mb-0">Alokasi Pembayaran</h5>
                        </div>
                        <div class="card-body">
                            <div class="table-responsive">
                                <table class="table table-striped" id="allocations-table">
                                    <thead>
                                        <tr>
                                            <th>Referensi Penjualan</th>
                                            <th>Perusahaan</th>
                                            <th>Tanggal Jatuh Tempo</th>
                                            <th>Total Penjualan</th>
                                            <th>Saldo Tagihan</th>
                                            <th>Identitas POS</th>
                                            <th class="text-end">Jumlah Pembayaran</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        @foreach($candidates as $candidate)
                                            @php
                                                $isStarting = $candidate->id === $startingSale->id;
                                                $defaultAmount = $isStarting ? $candidate->live_due_amount : 0;
                                                $posIdentifier = '';
                                                if ($candidate->posCheckout) {
                                                    $posIdentifier .= 'Bon: ' . ($candidate->posCheckout->receipt_number ?? 'N/A');
                                                    if ($candidate->posCheckout->transactions?->first()) {
                                                        $posIdentifier .= ' (' . $candidate->posCheckout->transactions->first()->code . ')';
                                                    }
                                                } elseif ($candidate->checkoutSale?->checkout) {
                                                    $posIdentifier .= 'Bon: ' . ($candidate->checkoutSale->checkout->receipt_number ?? 'N/A');
                                                    if ($candidate->checkoutSale->checkout->transactions?->first()) {
                                                        $posIdentifier .= ' (' . $candidate->checkoutSale->checkout->transactions->first()->code . ')';
                                                    }
                                                }
                                            @endphp
                                            <tr data-sale-id="{{ $candidate->id }}" data-live-due="{{ $candidate->live_due_amount }}">
                                                <td>
                                                    <strong>{{ $candidate->reference }}</strong>
                                                    @if($candidate->imported_reference)
                                                        <br><small class="text-muted">{{ $candidate->imported_reference }}</small>
                                                    @endif
                                                </td>
                                                <td>{{ $candidate->tenantSetting->company_name ?? 'N/A' }}</td>
                                                <td>{{ $candidate->due_date ? \Carbon\Carbon::parse($candidate->due_date)->format('d M Y') : '-' }}</td>
                                                <td class="text-end">{{ format_currency($candidate->total_amount) }}</td>
                                                <td class="text-end">{{ format_currency($candidate->live_due_amount) }}</td>
                                                <td>
                                                    @if($posIdentifier)
                                                        <small>{{ $posIdentifier }}</small>
                                                    @else
                                                        <span class="text-muted">-</span>
                                                    @endif
                                                </td>
                                                <td class="text-end">
                                                    <input type="number"
                                                           name="allocations[{{ $candidate->id }}]"
                                                           class="form-control allocation-input text-end"
                                                           step="0.01"
                                                           min="0"
                                                           value="{{ old('allocations.' . $candidate->id, $defaultAmount) }}"
                                                           data-max="{{ $candidate->live_due_amount }}"
                                                           placeholder="0.00">
                                                </td>
                                            </tr>
                                        @endforeach
                                    </tbody>
                                    <tfoot>
                                        <tr class="table-info">
                                            <td colspan="6" class="text-end"><strong>Total Alokasi</strong></td>
                                            <td class="text-end"><strong id="total-allocation">{{ format_currency(0) }}</strong></td>
                                        </tr>
                                    </tfoot>
                                </table>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="col-lg-12">
                    <div class="form-group">
                        <button type="submit" class="btn btn-primary">Buat Pembayaran <i class="bi bi-check"></i></button>
                        <a href="{{ route('sales.global-payments.index') }}" class="btn btn-secondary">Batal</a>
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

            function formatCurrency(num) {
                return currencySymbol + parseFloat(num || 0).toLocaleString('en-US', {
                    minimumFractionDigits: 2,
                    maximumFractionDigits: 2
                });
            }

            function updateTotalAllocation() {
                var total = 0;
                $('.allocation-input').each(function () {
                    var val = parseFloat($(this).val()) || 0;
                    total += val;
                });
                $('#total-allocation').text(formatCurrency(total));
            }

            // Update total on any allocation input change
            $(document).on('change keyup', '.allocation-input', function () {
                updateTotalAllocation();
            });

            // Initialize total
            updateTotalAllocation();

            // Dropzone configuration
            if (typeof Dropzone !== 'undefined') {
                Dropzone.autoDiscover = false;
                var myDropzone = new Dropzone('#file-dropzone', {
                    url: '{{ route("temp-files.upload") }}',
                    maxFiles: 1,
                    acceptedFiles: '.pdf,.jpg,.jpeg,.png,.gif',
                    maxFilesize: 10,
                    addRemoveLinks: true,
                    headers: {
                        'X-CSRF-TOKEN': $('meta[name="csrf-token"]').attr('content')
                    },
                    success: function (file, response) {
                        $('#attachment').val(response.path);
                    },
                    removedfile: function (file) {
                        $('#attachment').val('');
                        file.previewElement.remove();
                    }
                });
            }

            // Form submission
            $('#payment-form').on('submit', function (e) {
                var hasAllocation = false;
                $('.allocation-input').each(function () {
                    if (parseFloat($(this).val()) > 0) {
                        hasAllocation = true;
                    }
                });

                if (!hasAllocation) {
                    e.preventDefault();
                    alert('Minimal satu penjualan harus memiliki alokasi pembayaran yang positif.');
                    return false;
                }
            });
        });
    </script>
@endpush

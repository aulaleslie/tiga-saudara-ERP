@php use Modules\Purchase\Entities\Purchase; @endphp
@extends('layouts.app')

@section('title', 'Purchases Details')

@section('breadcrumb')
    <ol class="breadcrumb border-0 m-0">
        <li class="breadcrumb-item"><a href="{{ route('home') }}">Beranda</a></li>
        <li class="breadcrumb-item"><a href="{{ route('purchases.index') }}">Pembelian</a></li>
        <li class="breadcrumb-item active">Rincian</li>
    </ol>
@endsection

@section('content')
    <div class="container-fluid">
        <div class="row">
            <div class="col-lg-12">
                <div class="card">
                    <div class="card-header d-flex flex-wrap align-items-center">
                        <div>
                            Referensi: <strong>{{ $purchase->reference }}</strong>
                        </div>
                        <a target="_blank" class="btn btn-sm btn-secondary mfs-auto mfe-1 d-print-none"
                           href="{{ route('purchases.pdf', $purchase->id) }}">
                            <i class="bi bi-printer"></i> Print
                        </a>
                        <a target="_blank" class="btn btn-sm btn-info mfe-1 d-print-none"
                           href="{{ route('purchases.pdf', $purchase->id) }}">
                            <i class="bi bi-save"></i> Simpan
                        </a>
                        <a class="btn btn-sm btn-info mfe-1 d-print-none"
                           href="{{ route('purchases.index') }}">
                            <i class="bi bi-back"></i> Kembali
                        </a>
                    </div>
                    <div class="card-body">
                        <div class="row mb-4">
                            <div class="col-sm-4 mb-3 mb-md-0">
                                <h5 class="mb-2 border-bottom pb-2">Informasi Bisnis:</h5>
                                <div><strong>{{ settings()->company_name }}</strong></div>
                                <div>{{ settings()->company_address }}</div>
                                <div>Email: {{ settings()->company_email }}</div>
                                <div>Kontak: {{ settings()->company_phone }}</div>
                            </div>

                            <div class="col-sm-4 mb-3 mb-md-0">
                                <h5 class="mb-2 border-bottom pb-2">Informasi Pemasok:</h5>
                                <div><strong>{{ $supplier->supplier_name }}</strong></div>
                                <div>{{ $supplier->address }}</div>
                                <div>Email: {{ $supplier->supplier_email }}</div>
                                <div>Kontak: {{ $supplier->supplier_phone }}</div>
                            </div>

                            <div class="col-sm-4 mb-3 mb-md-0">
                                <h5 class="mb-2 border-bottom pb-2">Info Faktur:</h5>
                                <div>Faktur: <strong>INV/{{ $purchase->reference }}</strong></div>
                                <div>Tanggal: {{ \Carbon\Carbon::parse($purchase->date)->format('d M, Y') }}</div>
                                <div>
                                    Status: <strong>{{ $purchase->status }}</strong>
                                </div>
                                <div>
                                    Status Pembayaran: <strong>{{ $purchase->payment_status }}</strong>
                                </div>
                            </div>

                        </div>

                        <div class="table-responsive-sm">
                            <table class="table table-striped">
                                <thead>
                                <tr>
                                    <th class="align-middle">Produk</th>
                                    <th class="align-middle">Harga Satuan</th>
                                    <th class="align-middle">Kuantitas</th>
                                    <th class="align-middle">Diskon</th>
                                    <th class="align-middle">Pajak</th>
                                    <th class="align-middle">Jumlah Total</th>
                                </tr>
                                </thead>
                                <tbody>
                                @foreach($purchase->purchaseDetails as $item)
                                    <tr>
                                        <td class="align-middle">
                                            {{ $item->product_name }} <br>
                                            <span class="badge badge-success">
                                                {{ $item->product_code }}
                                            </span>
                                        </td>

                                        <td class="align-middle">{{ format_currency($item->price) }}</td>

                                        <td class="align-middle">
                                            {{ $item->quantity }}
                                        </td>

                                        <td class="align-middle">
                                            {{ format_currency($item->product_discount_amount) }}
                                        </td>

                                        <td class="align-middle">
                                            {{ format_currency($item->product_tax_amount) }}
                                        </td>

                                        <td class="align-middle">
                                            {{ format_currency($item->sub_total) }}
                                        </td>
                                    </tr>
                                @endforeach
                                </tbody>
                            </table>
                        </div>
                        <div class="row">
                            <div class="col-lg-4 col-sm-5 ml-md-auto">
                                <table class="table">
                                    <tbody>
                                    <tr>
                                        <td class="left"><strong>Diskon ({{ $purchase->discount_percentage }}
                                                %)</strong></td>
                                        <td class="right">{{ format_currency($purchase->discount_amount) }}</td>
                                    </tr>
                                    <tr>
                                        <td class="left"><strong>Pajak ({{ $purchase->tax_percentage }}%)</strong></td>
                                        <td class="right">{{ format_currency($purchase->tax_amount) }}</td>
                                    </tr>
                                    <tr>
                                        <td class="left"><strong>Pengiriman)</strong></td>
                                        <td class="right">{{ format_currency($purchase->shipping_amount) }}</td>
                                    </tr>
                                    <tr>
                                        <td class="left"><strong>Total Keseluruhan</strong></td>
                                        <td class="right">
                                            <strong>{{ format_currency($purchase->total_amount) }}</strong></td>
                                    </tr>
                                    </tbody>
                                </table>
                            </div>
                        </div>

                        <div class="row mt-4">
                            <div class="col-sm-12">
                                <h5 class="mb-2 border-bottom pb-2">Catatan:</h5>
                                <p>{{ $purchase->note ?? 'Tidak ada catatan.' }}</p>
                            </div>
                        </div>

                        <div class="row mt-4">
                            <div class="col-lg-12">
                                <div class="card">
                                    <div class="card-body">
                                        <h4 class="mb-3">Penerimaan Barang</h4>
                                        <div class="table-responsive">
                                            <table id="purchase-receivings-table" class="table table-striped table-bordered">
                                                <thead>
                                                <tr>
                                                    <th></th> <!-- Expand Button -->
                                                    <th>No. Delivery</th>
                                                    <th>No. Invoice</th>
                                                    <th>Tanggal</th>
                                                    <th>Total Diterima</th>
                                                </tr>
                                                </thead>
                                                <tbody>
                                                @foreach($receivedNotes as $receivedNote)
                                                    <!-- Main Row -->
                                                    <tr>
                                                        <td class="text-center">
                                                            <button type="button" class="btn btn-sm btn-outline-primary toggle-details"
                                                                    data-bs-target="#details-{{ $receivedNote->id }}"
                                                                    aria-expanded="false"
                                                                    aria-controls="details-{{ $receivedNote->id }}">
                                                                <i class="bi bi-plus-circle"></i>
                                                            </button>
                                                        </td>
                                                        <td>{{ $receivedNote->external_delivery_number ?? '-' }}</td>
                                                        <td>{{ $receivedNote->purchase->reference ?? '-' }}</td>
                                                        <td>{{ optional($receivedNote->created_at)->format('Y-m-d') }}</td>
                                                        <td>{{ $receivedNote->receivedNoteDetails->sum('quantity_received') }}</td>
                                                    </tr>

                                                    <!-- Expandable Details Row -->
                                                    <tr id="details-{{ $receivedNote->id }}" class="receiving-details-row collapse">
                                                        <td colspan="5">
                                                            @include('purchase::receivings.receiving-details', ['data' => $receivedNote])
                                                        </td>
                                                    </tr>
                                                @endforeach
                                                </tbody>
                                            </table>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- Payments Table -->
                        <div class="row mt-4">
                            <div class="col-lg-12">
                                <div class="card">
                                    <div class="card-body">
                                        <h4 class="mb-3">Pembayaran</h4>
                                        <div class="table-responsive">
                                            <table id="payments-table" class="table table-striped table-bordered">
                                                <thead>
                                                <tr>
                                                    <th>Tanggal</th>
                                                    <th>Referensi</th>
                                                    <th>Jumlah Pembayaran</th>
                                                    <th>Metode Pembayaran</th>
                                                    <th>Lampiran</th>
                                                    <th>Aksi</th>
                                                </tr>
                                                </thead>
                                            </table>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <div class="card-footer text-end">
                            @if ($purchase->status === Purchase::STATUS_DRAFTED)
                                <form method="POST" action="{{ route('purchases.updateStatus', $purchase->id) }}" class="d-inline">
                                    @csrf
                                    @method('PATCH')
                                    <input type="hidden" name="status" value="{{ Purchase::STATUS_WAITING_APPROVAL }}">
                                    <button type="submit" class="btn btn-warning">Kirim untuk Persetujuan</button>
                                </form>
                                <a href="{{ route('purchases.edit', $purchase->id) }}" class="btn btn-primary">
                                    <i class="bi bi-pencil mr-2"></i> Ubah
                                </a>
                            @endif

                            @can('purchases.approval')
                                @if ($purchase->status === Purchase::STATUS_WAITING_APPROVAL)
                                    <form method="POST" action="{{ route('purchases.updateStatus', $purchase->id) }}" class="d-inline">
                                        @csrf
                                        @method('PATCH')
                                        <input type="hidden" name="status" value="{{ Purchase::STATUS_APPROVED }}">
                                        <button type="submit" class="btn btn-success">Setuju</button>
                                    </form>
                                    <form method="POST" action="{{ route('purchases.updateStatus', $purchase->id) }}" class="d-inline">
                                        @csrf
                                        @method('PATCH')
                                        <input type="hidden" name="status" value="{{ Purchase::STATUS_REJECTED }}">
                                        <button type="submit" class="btn btn-danger">Tolak</button>
                                    </form>
                                @endif
                            @endcan

                            @can('purchases.receive')
                                @if ($purchase->status === Purchase::STATUS_APPROVED || $purchase->status === Purchase::STATUS_RECEIVED_PARTIALLY)
                                    <a href="{{ route('purchases.receive', $purchase->id) }}" class="btn btn-primary">
                                        Menerima
                                    </a>
                                @endif
                            @endcan
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
@endsection

@push('page_scripts')
    <script>
        $(document).ready(function () {
            $('#payments-table').DataTable({
                processing: true,
                serverSide: true,
                ajax: {
                    url: '{{ route("datatable.purchase_payments", ":purchase_id") }}'.replace(':purchase_id', '{{ $purchase->id }}'),
                },
                columns: [
                    { data: 'date', name: 'date', title: 'Tanggal' },
                    { data: 'reference', name: 'reference', title: 'Referensi' },
                    { data: 'amount', name: 'amount', title: 'Jumlah Pembayaran' },
                    { data: 'payment_method', name: 'payment_method', title: 'Metode Pembayaran' },
                    {
                        data: 'attachment',
                        name: 'attachment',
                        title: 'Lampiran',
                        render: function(data) {
                            return data ? data : 'Tidak ada';
                        }
                    },
                    {
                        data: 'action',
                        name: 'action',
                        orderable: false,
                        searchable: false,
                        title: 'Aksi'
                    },
                ]
            });
        });
    </script>

    <script>
        (function () {
            function initReceivingsToggle() {
                if (typeof bootstrap === 'undefined' || !bootstrap.Collapse) {
                    return;
                }

                const table = document.getElementById('purchase-receivings-table');
                if (!table) {
                    return;
                }

                table.addEventListener('click', function (event) {
                    const button = event.target.closest('button.toggle-details');
                    if (!button) {
                        return;
                    }

                    event.preventDefault();

                    const targetSelector = button.getAttribute('data-bs-target');
                    if (!targetSelector) {
                        return;
                    }

                    const detailRow = table.querySelector(targetSelector);
                    if (!detailRow) {
                        return;
                    }

                    const collapse = bootstrap.Collapse.getOrCreateInstance(detailRow, { toggle: false });
                    const isExpanded = button.getAttribute('aria-expanded') === 'true';

                    if (isExpanded) {
                        collapse.hide();
                    } else {
                        collapse.show();
                    }
                });

                table.addEventListener('shown.bs.collapse', function (event) {
                    const row = event.target;
                    if (!row.id) {
                        return;
                    }

                    const trigger = table.querySelector(`button.toggle-details[data-bs-target="#${row.id}"]`);
                    if (!trigger) {
                        return;
                    }

                    trigger.setAttribute('aria-expanded', 'true');

                    const icon = trigger.querySelector('i');
                    if (icon) {
                        icon.classList.remove('bi-plus-circle');
                        icon.classList.add('bi-dash-circle');
                    }
                });

                table.addEventListener('hidden.bs.collapse', function (event) {
                    const row = event.target;
                    if (!row.id) {
                        return;
                    }

                    const trigger = table.querySelector(`button.toggle-details[data-bs-target="#${row.id}"]`);
                    if (!trigger) {
                        return;
                    }

                    trigger.setAttribute('aria-expanded', 'false');

                    const icon = trigger.querySelector('i');
                    if (icon) {
                        icon.classList.remove('bi-dash-circle');
                        icon.classList.add('bi-plus-circle');
                    }
                });

                if (window.jQuery) {
                    window.jQuery(table).on('search.dt', function () {
                        table.querySelectorAll('tr.collapse.show').forEach(function (row) {
                            const instance = bootstrap.Collapse.getInstance(row);
                            if (instance) {
                                instance.hide();
                            }
                        });
                    });
                }
            }

            if (document.readyState === 'loading') {
                document.addEventListener('DOMContentLoaded', initReceivingsToggle);
            } else {
                initReceivingsToggle();
            }
        })();
    </script>
@endpush

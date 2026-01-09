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
                                <div>Tanggal Jatuh Tempo: {{ \Carbon\Carbon::parse($purchase->due_date)->format('d M, Y') }}</div>
                                <div class="mt-2">
                                    <livewire:purchase.supplier-purchase-number-editor
                                        :purchaseId="$purchase->id"
                                        :key="'supplier-purchase-number-' . $purchase->id"
                                    />
                                </div>
                                <div class="mt-2">
                                    <livewire:purchase.tax-ref-no-editor
                                        :purchaseId="$purchase->id"
                                        :key="'tax-ref-no-' . $purchase->id"
                                    />
                                </div>
                                <div class="mt-2">
                                    <div>Tags:</div>
                                    <div>
                                        @forelse ($purchase->tags as $tag)
                                            <span class="badge badge-secondary">
                                                {{ is_array($tag->name) ? ($tag->name['en'] ?? reset($tag->name)) : $tag->name }}
                                            </span>
                                        @empty
                                            <span class="text-muted">-</span>
                                        @endforelse
                                    </div>
                                </div>
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
                                    <th class="align-middle" style="width: 15%;">Produk</th>
                                    <th class="align-middle">Harga Satuan</th>
                                    <th class="align-middle">Kuantitas</th>
                                    <th class="align-middle">Diskon</th>
                                    @if($purchase->purchaseDetails->sum('product_tax_amount') > 0)
                                        <th class="align-middle">DPP</th>
                                        <th class="align-middle">Tax %</th>
                                    @endif
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

                                        <td class="align-middle">{{ formatRupiah($item->price) }}</td>

                                        <td class="align-middle">
                                            {{ $item->quantity }}
                                            @php
                                                $breakdown = calculateQuantityBreakdown($item->product_id, $item->quantity);
                                            @endphp
                                            @if($breakdown)
                                                <div class="text-muted small mt-1">
                                                    {{ $breakdown }}
                                                </div>
                                            @endif
                                        </td>

                                        <td class="align-middle">
                                            {{ formatRupiah($item->product_discount_amount) }}
                                        </td>

                                        @if($purchase->purchaseDetails->sum('product_tax_amount') > 0)
                                            <td class="align-middle">
                                                {{ formatRupiah($item->sub_total - $item->product_tax_amount - $item->product_discount_amount) }}
                                            </td>
                                            <td class="align-middle">
                                                {{ $item->tax ? $item->tax->value . '%' : '-' }}
                                            </td>
                                        @endif

                                        <td class="align-middle">
                                            {{ formatRupiah($item->sub_total) }}
                                        </td>
                                    </tr>
                                @endforeach
                                </tbody>
                            </table>
                        </div>
                        <div class="row">
                            <div class="col-lg-4 col-sm-5 ml-md-auto">
                                @php
                                    $totalPurchaseTax = $purchase->purchaseDetails->sum('product_tax_amount');
                                    $dppAmount = $purchase->purchaseDetails->sum('sub_total') - $totalPurchaseTax - $purchase->discount_amount;
                                @endphp
                                <table class="table">
                                    <tbody>
                                    @if($totalPurchaseTax > 0)
                                        <tr>
                                            <td class="left"><strong>DPP (Dasar Pengenaan Pajak)</strong></td>
                                            <td class="right">{{ formatRupiah($dppAmount) }}</td>
                                        </tr>
                                    @endif
                                    <tr>
                                        <td class="left"><strong>Diskon ({{ $purchase->discount_percentage }} %)</strong></td>
                                        <td class="right">{{ formatRupiah($purchase->discount_amount) }}</td>
                                    </tr>
                                    @if($totalPurchaseTax > 0)
                                        <tr>
                                            <td class="left"><strong>Pajak</strong></td>
                                            <td class="right">{{ formatRupiah($totalPurchaseTax) }}</td>
                                        </tr>
                                    @endif
                                    <tr>
                                        <td class="left"><strong>Pengiriman</strong></td>
                                        <td class="right">{{ formatRupiah($purchase->shipping_amount) }}</td>
                                    </tr>
                                    <tr>
                                        <td class="left"><strong>Total Keseluruhan</strong></td>
                                        <td class="right">
                                            <strong>{{ formatRupiah($purchase->total_amount) }}</strong></td>
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
                                                    <th>Lokasi</th>
                                                    <th>Total Diterima</th>
                                                    <th>Status</th>
                                                    @can('purchaseReceivings.approval')
                                                        <th>Aksi</th>
                                                    @endcan
                                                </tr>
                                                </thead>
                                                <tbody>
                                                @foreach($receivedNotes as $receivedNote)
                                                    <!-- Main Row -->
                                                    <tr>
                                                        <td class="text-center">
                                                            <button type="button" class="btn btn-sm btn-outline-primary toggle-details"
                                                                    data-details-target="details-{{ $receivedNote->id }}"
                                                                    aria-expanded="false"
                                                                    aria-controls="details-{{ $receivedNote->id }}">
                                                                <i class="bi bi-plus-circle"></i>
                                                            </button>
                                                        </td>
                                                        <td>{{ $receivedNote->external_delivery_number ?? '-' }}</td>
                                                        <td>{{ $receivedNote->purchase->reference ?? '-' }}</td>
                                                        <td>{{ optional($receivedNote->created_at)->format('Y-m-d') }}</td>
                                                        <td>{{ $receivedNote->location->name ?? '-' }}</td>
                                                        <td>{{ $receivedNote->receivedNoteDetails->sum('quantity_received') }}</td>
                                                        <td>
                                                            @if($receivedNote->isPending())
                                                                <span class="badge badge-warning">Menunggu Persetujuan</span>
                                                            @elseif($receivedNote->isApproved())
                                                                <span class="badge badge-success">Disetujui</span>
                                                            @elseif($receivedNote->isRejected())
                                                                <span class="badge badge-danger">Ditolak</span>
                                                            @endif
                                                        </td>
                                                        @can('purchaseReceivings.approval')
                                                            <td>
                                                                @if($receivedNote->isPending())
                                                                    <form action="{{ route('receivings.approve', $receivedNote) }}" method="POST" class="d-inline">
                                                                        @csrf
                                                                        <button type="submit" class="btn btn-sm btn-success" title="Setujui">
                                                                            <i class="bi bi-check-lg"></i>
                                                                        </button>
                                                                    </form>
                                                                    <button type="button" class="btn btn-sm btn-danger" title="Tolak" 
                                                                            data-toggle="modal" data-target="#rejectModal{{ $receivedNote->id }}">
                                                                        <i class="bi bi-x-lg"></i>
                                                                    </button>
                                                                    
                                                                    <!-- Reject Modal -->
                                                                    <div class="modal fade" id="rejectModal{{ $receivedNote->id }}" tabindex="-1">
                                                                        <div class="modal-dialog">
                                                                            <div class="modal-content">
                                                                                <form action="{{ route('receivings.reject', $receivedNote) }}" method="POST">
                                                                                    @csrf
                                                                                    <div class="modal-header">
                                                                                        <h5 class="modal-title">Tolak Penerimaan</h5>
                                                                                        <button type="button" class="close" data-dismiss="modal">
                                                                                            <span>&times;</span>
                                                                                        </button>
                                                                                    </div>
                                                                                    <div class="modal-body">
                                                                                        <div class="form-group">
                                                                                            <label for="rejection_reason">Alasan Penolakan <span class="text-danger">*</span></label>
                                                                                            <textarea name="rejection_reason" class="form-control" rows="3" required></textarea>
                                                                                        </div>
                                                                                    </div>
                                                                                    <div class="modal-footer">
                                                                                        <button type="button" class="btn btn-secondary" data-dismiss="modal">Batal</button>
                                                                                        <button type="submit" class="btn btn-danger">Tolak</button>
                                                                                    </div>
                                                                                </form>
                                                                            </div>
                                                                        </div>
                                                                    </div>
                                                                @elseif($receivedNote->isRejected())
                                                                    <span class="text-muted small" title="{{ $receivedNote->rejection_reason }}">
                                                                        <i class="bi bi-info-circle"></i> {{ Str::limit($receivedNote->rejection_reason, 30) }}
                                                                    </span>
                                                                @else
                                                                    <span class="text-muted">-</span>
                                                                @endif
                                                            </td>
                                                        @endcan
                                                    </tr>

                                                    <!-- Expandable Details Row -->
                                                    <tr id="details-{{ $receivedNote->id }}" class="receiving-details-row d-none">
                                                        <td colspan="{{ Gate::allows('purchaseReceivings.approval') ? 8 : 7 }}">
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
                const table = document.getElementById('purchase-receivings-table');
                if (!table) {
                    return;
                }

                table.addEventListener('click', function (event) {
                    const button = event.target.closest('button.toggle-details');
                    if (!button) {
                        return;
                    }

                    const targetId = button.getAttribute('data-details-target');
                    if (!targetId) {
                        return;
                    }

                    const detailRow = document.getElementById(targetId);
                    if (!detailRow) {
                        return;
                    }

                    const icon = button.querySelector('i');
                    const isHidden = detailRow.classList.contains('d-none');

                    if (isHidden) {
                        detailRow.classList.remove('d-none');
                        button.setAttribute('aria-expanded', 'true');
                        if (icon) {
                            icon.classList.remove('bi-plus-circle');
                            icon.classList.add('bi-dash-circle');
                        }
                    } else {
                        detailRow.classList.add('d-none');
                        button.setAttribute('aria-expanded', 'false');
                        if (icon) {
                            icon.classList.remove('bi-dash-circle');
                            icon.classList.add('bi-plus-circle');
                        }
                    }
                });

                if (window.jQuery) {
                    const $table = window.jQuery(table);
                    if ($table && $table.on) {
                        $table.on('search.dt', function () {
                            table.querySelectorAll('tr.receiving-details-row').forEach(function (row) {
                                if (!row.classList.contains('d-none')) {
                                    row.classList.add('d-none');
                                    const trigger = table.querySelector(`button.toggle-details[data-details-target="${row.id}"]`);
                                    if (trigger) {
                                        trigger.setAttribute('aria-expanded', 'false');
                                        const triggerIcon = trigger.querySelector('i');
                                        if (triggerIcon) {
                                            triggerIcon.classList.remove('bi-dash-circle');
                                            triggerIcon.classList.add('bi-plus-circle');
                                        }
                                    }
                                }
                            });
                        });
                    }
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

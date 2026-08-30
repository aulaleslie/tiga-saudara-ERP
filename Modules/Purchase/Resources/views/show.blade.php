@php use Illuminate\Support\Str; @endphp
@php use Modules\Purchase\Entities\Purchase; @endphp
@extends('layouts.app')

@section('title', 'Purchases Details')

@section('breadcrumb')
    <ol class="breadcrumb border-0 m-0">
        <li class="breadcrumb-item"><a href="{{ route('home') }}">Beranda</a></li>
        @if(isset($globalMode) && $globalMode)
            <li class="breadcrumb-item"><a href="{{ route('purchases.global-payments.index') }}">Pembayaran Pembelian Global</a></li>
        @else
            <li class="breadcrumb-item"><a href="{{ route('purchases.index') }}">Pembelian</a></li>
        @endif
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
                            @if($purchase->isConsignmentBilling())
                                <span class="badge badge-primary ml-2">CONSIGNMENT BILLING</span>
                            @endif
                        </div>
                        @if(!(isset($globalMode) && $globalMode))
                            <a target="_blank" class="btn btn-sm btn-secondary mfs-auto mfe-1 d-print-none"
                               href="{{ route('purchases.pdf', $purchase->id) }}">
                                <i class="bi bi-printer"></i> Print
                            </a>
                            <a target="_blank" class="btn btn-sm btn-info mfe-1 d-print-none"
                               href="{{ route('purchases.pdf', $purchase->id) }}">
                                <i class="bi bi-save"></i> Simpan
                            </a>
                            @can('purchases.create')
                                <a class="btn btn-sm btn-outline-secondary mfe-1 d-print-none"
                                   href="{{ route('purchases.create', ['duplicate' => $purchase->id]) }}">
                                    <i class="bi bi-files"></i> Duplikat
                                </a>
                            @endcan
                        @endif
                        <a class="btn btn-sm btn-info {{ (isset($globalMode) && $globalMode) ? 'mfs-auto' : '' }} mfe-1 d-print-none"
                           href="{{ (isset($globalMode) && $globalMode) ? route('purchases.global-payments.index') : route('purchases.index') }}">
                            <i class="bi bi-back"></i> Kembali
                        </a>
                    </div>
                    <div class="card-body">
                        @if($purchase->isConsignmentBilling() && $purchase->consignmentBillingConfirmation)
                            <div class="alert alert-info border-left-info shadow-sm mb-4">
                                <i class="bi bi-link-45deg mr-1"></i>
                                <strong>Dokumen Pembelian Hasil Konversi Tagihan Konsinyasi:</strong>
                                Dikonversi dari Konfirmasi Alokasi
                                <a href="{{ route('consignments.confirmations.show', $purchase->consignmentBillingConfirmation->id) }}" class="font-weight-bold alert-link">
                                    #{{ $purchase->consignmentBillingConfirmation->confirmation_number }}
                                </a>
                                pada {{ $purchase->consignmentBillingConfirmation->billed_at?->format('d/m/Y H:i') }}.
                                Item dan nilai komersial bersifat read-only.
                            </div>
                        @endif

                        <div class="row mb-4">
                            <div class="col-sm-4 mb-3 mb-md-0">
                                <h5 class="mb-2 border-bottom pb-2">Informasi Bisnis:</h5>
                                @if(isset($globalMode) && $globalMode && isset($setting))
                                    <div><strong>{{ $setting->company_name }}</strong></div>
                                    <div>{{ $setting->company_address }}</div>
                                    <div>Email: {{ $setting->company_email }}</div>
                                    <div>Kontak: {{ $setting->company_phone }}</div>
                                @else
                                    <div><strong>{{ settings()->company_name }}</strong></div>
                                    <div>{{ settings()->company_address }}</div>
                                    <div>Email: {{ settings()->company_email }}</div>
                                    <div>Kontak: {{ settings()->company_phone }}</div>
                                @endif
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
                                <div>Tanggal: {{ \Carbon\Carbon::parse($purchase->effective_date)->format('d M, Y') }}</div>
                                <div>Tanggal Jatuh Tempo: {{ \Carbon\Carbon::parse($purchase->due_date)->format('d M, Y') }}</div>
                                <div class="mt-2">
                                    @if(isset($globalMode) && $globalMode)
                                        <livewire:purchase.supplier-purchase-number-editor
                                            :purchaseId="$purchase->id"
                                            :globalMode="true"
                                            :key="'supplier-purchase-number-' . $purchase->id"
                                        />
                                    @else
                                        <livewire:purchase.supplier-purchase-number-editor
                                            :purchaseId="$purchase->id"
                                            :key="'supplier-purchase-number-' . $purchase->id"
                                        />
                                    @endif
                                </div>
                                <div class="mt-2">
                                    @if(isset($globalMode) && $globalMode)
                                        <livewire:purchase.tax-ref-no-editor
                                            :purchaseId="$purchase->id"
                                            :globalMode="true"
                                            :key="'tax-ref-no-' . $purchase->id"
                                        />
                                    @else
                                        <livewire:purchase.tax-ref-no-editor
                                            :purchaseId="$purchase->id"
                                            :key="'tax-ref-no-' . $purchase->id"
                                        />
                                    @endif
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
                                @if($purchase->status === Purchase::STATUS_REJECTED)
                                    <div class="alert alert-danger mt-2">
                                        <strong>Alasan Penolakan:</strong><br>
                                        {{ $purchase->rejection_note }}
                                    </div>
                                @endif
                                <div>
                                    Status Pembayaran: <strong>{{ \App\Constants\PaymentStatus::label($purchase->payment_status) }}</strong>
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
                                        <th class="align-middle">Pajak %</th>
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
                                            @if($purchase->isConsignmentBilling() && $item->consignmentLineages->isNotEmpty())
                                                <div class="mt-2 small text-muted">
                                                    <span class="badge badge-light border">Asal Konsinyasi</span>
                                                    @foreach($item->consignmentLineages as $lineage)
                                                        <div>
                                                            Penerimaan #{{ $lineage->consignment_receiving_detail_id }}
                                                            &middot; Qty {{ number_format($lineage->billed_base_quantity, 3) }}
                                                            @if($lineage->consignment_serialized_allocation_id)
                                                                &middot; SN
                                                            @endif
                                                        </div>
                                                    @endforeach
                                                </div>
                                            @endif
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
                                            @if($item->uomNormalizationLines->count() > 0)
                                                @foreach($item->uomNormalizationLines as $normLine)
                                                    <div class="alert alert-info py-1 px-2 mt-2 mb-0 small">
                                                        @if($normLine->batch->isLegacyFormat())
                                                            <i class="cil-info"></i> Base UOM corrected (legacy record) to {{ optional($normLine->batch->legacyBaseUnit)->name }} on {{ $normLine->batch->executed_at ? $normLine->batch->executed_at->format('Y-m-d') : $normLine->created_at->format('Y-m-d') }}.
                                                        @else
                                                            <i class="cil-info"></i> Base UOM corrected from {{ optional($normLine->batch->oldBaseUnit)->name }} to {{ optional($normLine->batch->newBaseUnit)->name }} on {{ $normLine->batch->executed_at ? $normLine->batch->executed_at->format('Y-m-d') : $normLine->created_at->format('Y-m-d') }}.
                                                        @endif
                                                    </div>
                                                @endforeach
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

                        <div class="row mt-4" id="purchase-attachments">
                            <div class="col-sm-12">
                                <h5 class="mb-2 border-bottom pb-2">Catatan:</h5>
                                @if(isset($globalMode) && $globalMode)
                                    <livewire:purchase.purchase-note-editor
                                        :purchaseId="$purchase->id"
                                        :globalMode="true"
                                        :key="'purchase-note-' . $purchase->id"
                                    />
                                @else
                                    <livewire:purchase.purchase-note-editor
                                        :purchaseId="$purchase->id"
                                        :key="'purchase-note-' . $purchase->id"
                                    />
                                @endif
                            </div>
                        </div>

                        @php $attachments = $purchase->getMedia('attachments'); @endphp
                        <div class="row mt-4">
                            <div class="col-sm-12">
                                <h5 class="mb-2 border-bottom pb-2">Lampiran:</h5>
                                @can('purchases.update')
                                    @if(!$purchase->isArchived() && !(isset($globalMode) && $globalMode))
                                        <form action="{{ route('purchases.attachments.store', $purchase->id) }}"
                                          method="POST"
                                          enctype="multipart/form-data"
                                          class="mb-3">
                                        @csrf
                                        <div class="form-group mb-2">
                                            <label for="purchase-attachment" class="font-weight-bold">Tambah Lampiran</label>
                                            <div class="attachment-uploader">
                                                <div class="attachment-uploader__icon">
                                                    <i class="bi bi-paperclip"></i>
                                                </div>
                                                <div class="attachment-uploader__body">
                                                    <div class="custom-file">
                                                        <input type="file"
                                                               name="attachment"
                                                               id="purchase-attachment"
                                                               class="custom-file-input @error('attachment') is-invalid @enderror">
                                                        <label class="custom-file-label" for="purchase-attachment">Pilih file...</label>
                                                        @error('attachment')
                                                        <div class="invalid-feedback">{{ $message }}</div>
                                                        @enderror
                                                    </div>
                                                    <small class="form-text text-muted">
                                                        Maksimal 1 lampiran per unggah. Gambar akan dikompresi berulang hingga di bawah 1MB,
                                                        berkas lain akan di-zip jika di atas 1MB.
                                                    </small>
                                                </div>
                                            </div>
                                        </div>
                                        <button type="submit" class="btn btn-sm btn-primary">Upload Lampiran</button>
                                    </form>
                                    @endif
                                @endcan

                                @if($attachments->isEmpty())
                                    <p class="text-muted">Tidak ada lampiran.</p>
                                @else
                                    <ul class="list-group">
                                        @foreach($attachments as $media)
                                            @php
                                                $displayName = $media->getCustomProperty('original_name') ?: $media->file_name;
                                                $mimeType = $media->mime_type ?? '';
                                                $isImage = Str::startsWith($mimeType, 'image/');
                                                $isImmutable = $media->getCustomProperty('source') === 'CONSIGNMENT_BILLING';
                                            @endphp
                                            <li class="list-group-item d-flex flex-wrap justify-content-between align-items-center">
                                                <div>
                                                    <div>
                                                        {{ $displayName }}
                                                        @if($isImmutable)
                                                            <span class="badge badge-info ml-1" title="Faktur Supplier Konsinyasi (Bukti Permanen)">
                                                                <i class="bi bi-shield-check"></i> Faktur Konsinyasi (Permanen)
                                                            </span>
                                                        @endif
                                                    </div>
                                                    <small class="text-muted">{{ $media->humanReadableSize }}</small>
                                                </div>
                                                <div class="btn-group mt-2 mt-sm-0">
                                                    <a class="btn btn-sm btn-outline-primary" href="{{ $media->getUrl() }}"
                                                       @if($isImage)
                                                           target="_blank" rel="noopener"
                                                       @else
                                                           download
                                                       @endif>
                                                        Preview
                                                    </a>
                                                    <a class="btn btn-sm btn-outline-secondary" href="{{ $media->getUrl() }}" download>
                                                        Download
                                                    </a>
                                                    @can('purchases.update')
                                                        @if(!$purchase->isArchived() && !(isset($globalMode) && $globalMode) && !$isImmutable)
                                                            <form method="POST"
                                                                  action="{{ route('purchases.attachments.destroy', [$purchase->id, $media->id]) }}"
                                                                  onsubmit="return confirm('Hapus lampiran ini?');"
                                                                  class="d-inline">
                                                                @csrf
                                                                @method('DELETE')
                                                                <button type="submit" class="btn btn-sm btn-outline-danger">Hapus</button>
                                                            </form>
                                                        @endif
                                                    @endcan
                                                </div>
                                            </li>
                                        @endforeach
                                    </ul>
                                @endif
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
                                                    @can('purchases.receive.approval')
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
                                                        @can('purchases.receive.approval')
                                                            <td>
                                                                @if($receivedNote->isPending())
                                                                    <form action="{{ route('receivings.approve', $receivedNote) }}" method="POST" class="d-inline approve-receiving-form" onsubmit="handleApproveReceiving(this, event); return false;">
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
                                                        <td colspan="{{ Gate::allows('purchases.receive.approval') ? 8 : 7 }}">
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
                                        <div class="d-flex justify-content-between align-items-center mb-3">
                                            <h4 class="mb-0">Pembayaran</h4>
                                            @can('purchasePayments.create')
                                                @if($purchase->status === Purchase::STATUS_RECEIVED || $purchase->status === Purchase::STATUS_RECEIVED_PARTIALLY)
                                                    @if((isset($globalMode) && $globalMode ? $purchase->live_due_amount : $purchase->due_amount) > 0)
                                                        <a href="{{ isset($globalMode) && $globalMode ? route('purchases.global-payments.create', ['supplier' => $purchase->supplier_id, 'purchase_id' => $purchase->id]) : route('purchase-payments.create', $purchase->id) }}" class="btn btn-primary btn-sm">
                                                            Tambah Pembayaran <i class="bi bi-plus"></i>
                                                        </a>
                                                    @endif
                                                @endif
                                            @endcan
                                        </div>
                                        <div class="table-responsive">
                                            <table id="payments-table" class="table table-striped table-bordered">
                                                <thead>
                                                <tr>
                                                    <th>Tanggal</th>
                                                    <th>Referensi</th>
                                                    <th>Jumlah Pembayaran</th>
                                                    <th>Metode Pembayaran</th>
                                                    <th>Catatan</th>
                                                    <th>Lampiran</th>
                                                    <th>Status</th>
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
                            @if(isset($globalMode) && $globalMode)
                                @php
                                    $canMonetaryEditGlobal = auth()->user()->can('purchasePayments.global.access')
                                        && auth()->user()->can('purchases.update')
                                        && auth()->user()->can('purchases.received.monetary.edit')
                                        && $purchase->resolveEditMode() === \Modules\Purchase\Entities\Purchase::EDIT_MODE_MONETARY_ONLY;
                                @endphp
                                @if($canMonetaryEditGlobal)
                                    <a href="{{ route('purchases.global-payments.edit-monetary', $purchase->id) }}" class="btn btn-warning">
                                        <i class="bi bi-pencil-square mr-2"></i> Ubah Nilai (Moneter)
                                    </a>
                                @endif
                                @include('partials.date-adjustment-modal', ['document' => $purchase, 'globalMode' => true])
                            @else
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
                                        <button type="button" class="btn btn-danger" data-toggle="modal" data-target="#rejectPurchaseModal">
                                            Tolak
                                        </button>
                                    @endif
                                @endcan

                                @if ($purchase->status === Purchase::STATUS_REJECTED)
                                    <form method="POST" action="{{ route('purchases.updateStatus', $purchase->id) }}" class="d-inline">
                                        @csrf
                                        @method('PATCH')
                                        <input type="hidden" name="status" value="{{ Purchase::STATUS_DRAFTED }}">
                                        <button type="submit" class="btn btn-info">Selesaikan / Perbaiki (Draft)</button>
                                    </form>
                                @endif

                                @can('purchases.receive')
                                    @if (!$purchase->isArchived() && ($purchase->status === Purchase::STATUS_APPROVED || $purchase->status === Purchase::STATUS_RECEIVED_PARTIALLY))
                                        <a href="{{ route('purchases.receive', $purchase->id) }}" class="btn btn-primary">
                                            Menerima
                                        </a>
                                    @endif
                                @endcan

                                @can('purchases.receive.complete_shortfall')
                                    @if (!$purchase->isArchived() && $purchase->status === Purchase::STATUS_RECEIVED_PARTIALLY)
                                        <button type="button" class="btn btn-success" wire:click="$dispatch('openReceivingCompletionModal', { purchase: {{ $purchase->id }} })">
                                            Selesaikan Penerimaan
                                        </button>
                                    @endif
                                @endcan

                                @can('correct', $purchase)
                                    @if (!$purchase->isArchived() && in_array($purchase->status, [Purchase::STATUS_RECEIVED, Purchase::STATUS_RECEIVED_PARTIALLY], true))
                                        <a href="{{ route('purchases.correction.edit', $purchase->id) }}" class="btn btn-warning">
                                            <i class="bi bi-pencil-square mr-2"></i> Koreksi Penerimaan
                                        </a>
                                    @endif
                                @endcan

                                @include('partials.date-adjustment-modal', ['document' => $purchase])
                            @endif
                        </div>
                    </div>
                </div>
            </div>
        </div>

    {{-- Over-Receive Error Modal --}}
    @include('purchase::partials.over-receive-error-modal')

    {{-- Reject Purchase Modal --}}
    <div class="modal fade" id="rejectPurchaseModal" tabindex="-1" role="dialog" aria-labelledby="rejectPurchaseModalLabel" aria-hidden="true">
        <div class="modal-dialog" role="document">
            <div class="modal-content">
                <form action="{{ route('purchases.updateStatus', $purchase->id) }}" method="POST">
                    @csrf
                    @method('PATCH')
                    <input type="hidden" name="status" value="{{ Purchase::STATUS_REJECTED }}">
                    <div class="modal-header">
                        <h5 class="modal-title" id="rejectPurchaseModalLabel">Tolak Pembelian</h5>
                        <button type="button" class="close" data-dismiss="modal" aria-label="Close">
                            <span aria-hidden="true">&times;</span>
                        </button>
                    </div>
                    <div class="modal-body">
                        <div class="form-group">
                            <label for="rejection_note">Alasan Penolakan <span class="text-danger">*</span></label>
                            <textarea name="rejection_note" id="rejection_note" rows="4" class="form-control" required placeholder="Masukkan alasan penolakan..."></textarea>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-dismiss="modal">Batal</button>
                        <button type="submit" class="btn btn-danger">Tolak Pembelian</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    {{-- Receiving Completion Modal --}}
    @can('purchases.receive.complete_shortfall')
        <livewire:purchase.modals.purchase-receiving-completion-modal />
    @endcan

    {{-- UOM Normalization Audit History --}}
    @php
        $normBatches = $normBatches ?? collect([]);
    @endphp
    @if($normBatches->isNotEmpty())
        <div class="row mt-4">
            <div class="col-12">
                <div class="card">
                    <div class="card-header bg-light">
                        <h6 class="mb-0"><i class="bi bi-arrow-repeat mr-2"></i> Riwayat Normalisasi UOM</h6>
                    </div>
                    <div class="card-body">
                        @foreach($normBatches as $normBatch)
                        <div class="card mb-3 border-info">
                            <div class="card-header">
                                <strong>Batch #{{ $normBatch->id }}</strong>
                                — {{ $normBatch->product->product_name ?? '—' }}
                                @if($normBatch->isLegacyFormat())
                                — {{ optional($normBatch->legacySourceUnit)->name }} → {{ optional($normBatch->legacyBaseUnit)->name }}
                                @else
                                — {{ optional($normBatch->oldBaseUnit)->name }} → {{ optional($normBatch->newBaseUnit)->name }}
                                @endif
                                (×{{ $normBatch->conversion_factor }})
                                <span class="float-right text-muted">
                                    {{ $normBatch->executed_at?->format('d-m-Y H:i') }}
                                    oleh {{ $normBatch->actor->name ?? '—' }}
                                </span>
                            </div>
                            <div class="card-body">
                                @if($normBatch->reason)
                                    <p class="mb-2"><strong>Alasan:</strong> {{ $normBatch->reason }}</p>
                                @endif
                                <div class="alert alert-info small mb-2">
                                    <i class="cil-info"></i>
                                    Harga satuan pembelian dapat dibulatkan sesuai presisi mata uang. Nilai subtotal pemasok tetap menjadi nilai otoritatif dan tidak berubah.
                                </div>
                                <table class="table table-bordered table-sm mb-0">
                                    <thead>
                                        <tr>
                                            <th>Detail Pembelian</th>
                                            <th>Qty Asal</th>
                                            <th>→ Qty Normal</th>
                                            <th>Harga Satuan Asal</th>
                                            <th>Harga Satuan Normal</th>
                                            <th>Sub Total Pemasok (Tetap)</th>
                                            <th>Transaksi ID</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        @foreach($normBatch->lines as $line)
                                        @php
                                            $hasRounding = $line->unit_price_rounding_effect !== null
                                                && abs((float) $line->unit_price_rounding_effect) > 0.0000001;
                                        @endphp
                                        <tr>
                                            <td>#{{ $line->purchase_detail_id }}</td>
                                            <td>{{ number_format($line->source_quantity, 3) }}</td>
                                            <td>{{ number_format($line->normalized_quantity, 3) }}</td>
                                            <td>{{ format_currency($line->source_unit_price) }}</td>
                                            <td>
                                                {{ format_currency($line->normalized_unit_price ?? $line->normalized_unit_cost) }}
                                                @if($hasRounding)
                                                    <span class="badge badge-warning ml-1" title="Harga satuan dibulatkan">
                                                        <i class="cil-warning"></i> Dibulatkan
                                                    </span>
                                                    <div class="text-muted small mt-1">
                                                        <div>Harga satuan tepat: Rp{{ number_format($line->normalized_unit_price + $line->unit_price_rounding_effect, 6, ',', '.') }}</div>
                                                        <div>Harga satuan tersimpan: {{ format_currency($line->normalized_unit_price) }}</div>
                                                        <div>Selisih pembulatan tampilan: Rp{{ number_format($line->unit_price_rounding_effect, 6, ',', '.') }} per unit</div>
                                                    </div>
                                                @endif
                                            </td>
                                            <td>{{ format_currency($line->source_sub_total) }}</td>
                                            <td>{{ $line->transaction_id }}</td>
                                        </tr>
                                        @endforeach
                                    </tbody>
                                </table>
                                @if($normBatch->cost_outcome)
                                    @php
                                        $costOutcome = $normBatch->cost_outcome;
                                        // New format nests active-setting facts under
                                        // 'active_setting'; legacy/pre-cross-setting
                                        // batches wrote 'before'/'after' at the top level.
                                        $activeOutcome = $costOutcome['active_setting'] ?? $costOutcome;
                                        $priceOnlySettings = $costOutcome['price_only_settings'] ?? [];
                                    @endphp
                                    <div class="mt-2 small text-muted">
                                        <strong>HPP (Cabang Aktif):</strong>
                                        Sebelum: {{ format_currency($activeOutcome['before']['average_purchase_price'] ?? 0) }}
                                        → Sesudah: {{ format_currency($activeOutcome['after']['average_purchase_price'] ?? 0) }}
                                    </div>

                                    @if(!empty($priceOnlySettings))
                                        <div class="mt-3">
                                            <strong class="small">Rebase Biaya Pembelian — Cabang Lain (Harga Saja):</strong>
                                            <table class="table table-bordered table-sm mt-1 mb-0">
                                                <thead>
                                                    <tr>
                                                        <th>Cabang</th>
                                                        <th>HPP Rata-rata (Lama → Baru)</th>
                                                        <th>Harga Pembelian Terakhir (Lama → Baru)</th>
                                                        <th>Faktor</th>
                                                        <th>Efek Pembulatan</th>
                                                    </tr>
                                                </thead>
                                                <tbody>
                                                    @foreach($priceOnlySettings as $settingOutcome)
                                                    <tr>
                                                        <td>{{ $settingOutcome['setting_name'] ?? ('Setting #' . ($settingOutcome['setting_id'] ?? '—')) }}</td>
                                                        <td>
                                                            {{ format_currency($settingOutcome['before']['average_purchase_price'] ?? 0) }}
                                                            → {{ format_currency($settingOutcome['after']['average_purchase_price'] ?? 0) }}
                                                        </td>
                                                        <td>
                                                            {{ format_currency($settingOutcome['before']['last_purchase_price'] ?? 0) }}
                                                            → {{ format_currency($settingOutcome['after']['last_purchase_price'] ?? 0) }}
                                                        </td>
                                                        <td>{{ $settingOutcome['factor'] ?? '—' }}</td>
                                                        <td>
                                                            @if(isset($settingOutcome['rounding_effect']))
                                                                Rata-rata: {{ number_format($settingOutcome['rounding_effect']['average_purchase_price'] ?? 0, 6) }},
                                                                Terakhir: {{ number_format($settingOutcome['rounding_effect']['last_purchase_price'] ?? 0, 6) }}
                                                            @else
                                                                —
                                                            @endif
                                                        </td>
                                                    </tr>
                                                    @endforeach
                                                </tbody>
                                            </table>
                                            <p class="text-muted small mb-0 mt-1">
                                                Hanya biaya pembelian (HPP) yang disesuaikan di cabang lain ini. Harga jual dan harga tingkat tidak diubah.
                                            </p>
                                        </div>
                                    @endif
                                @endif
                            </div>
                        </div>
                        @endforeach
                    </div>
                </div>
            </div>
        </div>
    @endif
@endsection

@push('page_css')
    <style>
        .attachment-uploader {
            border: 1px dashed #b9c0c7;
            background: #f8f9fb;
            border-radius: 10px;
            padding: 12px 14px;
            display: flex;
            align-items: center;
            gap: 12px;
        }

        .attachment-uploader__icon {
            width: 40px;
            height: 40px;
            border-radius: 8px;
            background: #e7edf5;
            color: #2f3b4a;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 18px;
            flex: 0 0 40px;
        }

        .attachment-uploader__body {
            flex: 1 1 auto;
            min-width: 0;
        }

        .attachment-uploader .custom-file-label {
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
        }
    </style>
@endpush

@push('page_scripts')
    <script>
        $(document).ready(function () {
            $('#payments-table').DataTable({
                processing: true,
                serverSide: true,
                ajax: {
                    url: '{{ isset($globalMode) && $globalMode ? route("datatable.global_purchase_payments", ":purchase_id") : route("datatable.purchase_payments", ":purchase_id") }}'.replace(':purchase_id', '{{ $purchase->id }}'),
                },
                columns: [
                    { data: 'date', name: 'date', title: 'Tanggal' },
                    { data: 'reference', name: 'reference', title: 'Referensi' },
                    { data: 'amount', name: 'amount', title: 'Jumlah Pembayaran' },
                    { data: 'payment_method', name: 'payment_method', title: 'Metode Pembayaran' },
                    { data: 'note', name: 'note', title: 'Catatan', className: 'payment-note' },
                    {
                        data: 'attachment',
                        name: 'attachment',
                        title: 'Lampiran',
                        render: function(data) {
                            return data ? data : 'Tidak ada';
                        }
                    },
                    { data: 'status', name: 'status', title: 'Status', className: 'text-center' },
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

    <script>
        document.addEventListener('DOMContentLoaded', function () {
            var input = document.getElementById('purchase-attachment');
            if (!input) {
                return;
            }

            var label = input.nextElementSibling;
            var defaultLabel = label ? label.textContent : 'Pilih file...';

            input.addEventListener('change', function (event) {
                var name = event.target.files && event.target.files.length ? event.target.files[0].name : defaultLabel;
                if (label) {
                    label.textContent = name || defaultLabel;
                }
            });
        });
    </script>

    @if(!(isset($globalMode) && $globalMode))
    <script>
        document.addEventListener('DOMContentLoaded', function () {
            const overrideForm = document.getElementById('reportingDateOverrideForm');
            const clearForm = document.getElementById('clearReportingDateForm');
            const errorAlert = document.getElementById('reportingDateErrorAlert');
            const toggleClearBtn = document.getElementById('toggleClearMode');
            const createMode = document.getElementById('createOverrideMode');
            const clearMode = document.getElementById('clearOverrideMode');
            const modal = document.getElementById('reportingDateOverrideModal');

            function showError(message) {
                errorAlert.textContent = message;
                errorAlert.classList.remove('d-none');
            }

            function clearError() {
                errorAlert.classList.add('d-none');
                errorAlert.textContent = '';
            }

            function showSuccessToast(message) {
                Swal.fire({
                    icon: 'success',
                    title: 'Berhasil',
                    text: message,
                    toast: true,
                    position: 'top-end',
                    showConfirmButton: false,
                    timer: 3000,
                });
            }

            if (toggleClearBtn) {
                toggleClearBtn.addEventListener('click', function () {
                    if (createMode.classList.contains('d-none')) {
                        createMode.classList.remove('d-none');
                        clearMode.classList.add('d-none');
                        toggleClearBtn.textContent = 'Tampilkan Mode Hapus';
                        toggleClearBtn.classList.remove('btn-outline-info');
                        toggleClearBtn.classList.add('btn-outline-warning');
                    } else {
                        createMode.classList.add('d-none');
                        clearMode.classList.remove('d-none');
                        toggleClearBtn.textContent = 'Tampilkan Mode Ubah';
                        toggleClearBtn.classList.remove('btn-outline-warning');
                        toggleClearBtn.classList.add('btn-outline-info');
                    }
                    clearError();
                });
            }

            if (overrideForm) {
                overrideForm.addEventListener('submit', function (e) {
                    e.preventDefault();
                    clearError();

                    const reportingDate = document.getElementById('reporting_date').value;
                    const reason = document.getElementById('reason').value;

                    if (!reportingDate) {
                        showError('Tanggal pelaporan harus diisi');
                        return;
                    }

                    if (!reason.trim()) {
                        showError('Alasan harus diisi');
                        return;
                    }

                    fetch('{{ route("purchases.reporting-date.store", $purchase->id) }}', {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/json',
                            'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').getAttribute('content'),
                        },
                        body: JSON.stringify({
                            reporting_date: reportingDate,
                            reason: reason,
                        }),
                    })
                    .then(response => {
                        if (!response.ok) {
                            return response.json().then(data => {
                                throw new Error(data.message || 'Terjadi kesalahan server');
                            });
                        }
                        return response.json();
                    })
                    .then(data => {
                        if (data.success) {
                            showSuccessToast(data.message || 'Tanggal pelaporan berhasil disimpan');
                            setTimeout(() => location.reload(), 1500);
                        } else {
                            showError(data.message || 'Terjadi kesalahan');
                        }
                    })
                    .catch(error => {
                        console.error('Error:', error);
                        showError(error.message || 'Terjadi kesalahan saat mengirim data');
                    });
                });
            }

            if (clearForm) {
                clearForm.addEventListener('submit', function (e) {
                    e.preventDefault();
                    clearError();

                    const reason = document.getElementById('clearReason').value;

                    if (!reason.trim()) {
                        showError('Alasan penghapusan harus diisi');
                        return;
                    }

                    fetch('{{ route("purchases.reporting-date.destroy", $purchase->id) }}', {
                        method: 'DELETE',
                        headers: {
                            'Content-Type': 'application/json',
                            'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').getAttribute('content'),
                        },
                        body: JSON.stringify({
                            reason: reason,
                        }),
                    })
                    .then(response => {
                        if (!response.ok) {
                            return response.json().then(data => {
                                throw new Error(data.message || 'Terjadi kesalahan server');
                            });
                        }
                        return response.json();
                    })
                    .then(data => {
                        if (data.success) {
                            showSuccessToast(data.message || 'Override tanggal pelaporan berhasil dihapus');
                            setTimeout(() => location.reload(), 1500);
                        } else {
                            showError(data.message || 'Terjadi kesalahan');
                        }
                    })
                    .catch(error => {
                        console.error('Error:', error);
                        showError(error.message || 'Terjadi kesalahan saat mengirim data');
                    });
                });
            }
        });
    </script>
    @endif
@endpush

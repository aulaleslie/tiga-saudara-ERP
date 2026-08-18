@php use Carbon\Carbon;use Modules\Sale\Entities\Sale; @endphp
@extends('layouts.app')

@section('title', 'Rincian Penjualan')

@section('content')
    <div class="container-fluid">
        <div class="card">
            <div class="card-header d-flex flex-wrap align-items-center">
                <div>
                    Referensi: <strong>{{ $sale->reference ?? 'N/A' }}</strong>
                </div>

                @php $hasDispatches = isset($dispatches) && $dispatches->isNotEmpty(); @endphp

                @if(!$sale->isArchived())
                    @if($hasDispatches)
                        <a target="_blank"
                           href="{{ route('sales.deliverySlip', ['sale' => $sale->id, 'type' => 'delivery']) }}"
                           class="btn btn-sm btn-secondary mfs-auto mfe-1 d-print-none">
                            <i class="bi bi-truck"></i> Cetak Surat Jalan (Terakhir)
                        </a>
                    @else
                        <a class="btn btn-sm btn-secondary mfs-auto mfe-1 d-print-none" disabled
                           title="Belum ada pengeluaran/dispatch untuk dicetak">
                            <i class="bi bi-truck"></i> Surat Jalan
                        </a>
                    @endif
                    <a target="_blank"
                       href="{{ route('sales.invoicePdf', ['sale' => $sale->id, 'type' => 'invoice']) }}"
                       class="btn btn-sm btn-secondary mfe-1 d-print-none">
                        <i class="bi bi-truck"></i> Cetak Faktur
                    </a>
                @endif
                <a class="btn btn-sm btn-info mfs-auto mfe-1 d-print-none" href="{{ route('sales.index') }}">
                    <i class="bi bi-back"></i> Kembali
                </a>
            </div>
            <div class="card-body">
                <div class="row mb-4">
                    <!-- Informasi Bisnis -->
                    <div class="col-sm-4 mb-3 mb-md-0">
                        <h5 class="mb-2 border-bottom pb-2">Informasi Bisnis:</h5>
                        <div><strong>{{ optional(settings())->company_name }}</strong></div>
                        <div>{{ optional(settings())->company_address }}</div>
                        <div>Email: {{ optional(settings())->company_email }}</div>
                        <div>Kontak: {{ optional(settings())->company_phone }}</div>
                    </div>
                    <!-- Informasi Pelanggan -->
                    <div class="col-sm-4 mb-3 mb-md-0">
                        <h5 class="mb-2 border-bottom pb-2">Informasi Pelanggan:</h5>
                        <div><strong>{{ $customer->customer_name }}</strong></div>
                        <!-- Tambahkan info lain jika ada, seperti alamat, email, dsb. -->
                    </div>
                    <!-- Info Faktur -->
                    <div class="col-sm-4 mb-3 mb-md-0">
                        <h5 class="mb-2 border-bottom pb-2">Info Faktur:</h5>
                        @if($sale->imported_sales_reference_number)
                            <div>Nomor Penjualan Pelanggan: <strong>{{ $sale->imported_sales_reference_number }}</strong></div>
                        @endif
                        <div>Faktur: <strong>INV/{{ $sale->reference }}</strong></div>
                        <div>Tanggal: {{ Carbon::parse($sale->effective_date)->format('d M, Y') }}</div>
                        <div class="mt-2">
                            <livewire:sale.tax-ref-no-editor
                                :saleId="$sale->id"
                                :isArchived="$sale->isArchived()"
                                :key="'sale-tax-ref-no-' . $sale->id"
                            />
                        </div>
                        <div class="mt-2">
                            <div>Tags:</div>
                            <div>
                                @forelse ($sale->tags as $tag)
                                    <span class="badge badge-secondary">
                                        {{ is_array($tag->name) ? ($tag->name['en'] ?? reset($tag->name)) : $tag->name }}
                                    </span>
                                @empty
                                    <span class="text-muted">-</span>
                                @endforelse
                            </div>
                        </div>
                        <div>Status: <strong>{{ $sale->status }}</strong></div>
                        <div>Status Pembayaran: <strong>{{ \App\Constants\PaymentStatus::label($sale->payment_status) }}</strong></div>
                    </div>
                </div>

                @php
                    $standaloneBundles = $sale->bundleItems->filter(fn($item) => is_null($item->sale_detail_id));
                    $totalSaleTax = $sale->saleDetails->sum('product_tax_amount') + $standaloneBundles->sum('tax_amount');
                    $totalSubTotal = $sale->saleDetails->sum('sub_total') + $standaloneBundles->sum('sub_total');
                @endphp

                <!-- Detail Penjualan -->
                <div class="table-responsive-sm">
                    <table class="table table-striped">
                        <thead>
                        <tr>
                            <th class="align-middle" style="width: 15%;">Produk</th>
                            <th class="align-middle">Harga Satuan</th>
                            <th class="align-middle">Kuantitas</th>
                            <th class="align-middle">Diskon</th>
                            @if($totalSaleTax > 0)
                                <th class="align-middle">DPP</th>
                                <th class="align-middle">Pajak %</th>
                            @endif
                            <th class="align-middle">Jumlah Total</th>
                        </tr>
                        </thead>
                        <tbody>
                        @foreach($sale->saleDetails as $detail)
                            <tr>
                                <td class="align-middle">
                                    {{ $detail->product_name }} <br>
                                    <span class="badge bg-success">{{ $detail->product_code }}</span>
                                </td>
                                <td class="align-middle">{{ format_currency($detail->price) }}</td>
                                <td class="align-middle">{{ $detail->quantity }}</td>
                                <td class="align-middle">{{ format_currency($detail->product_discount_amount) }}</td>
                                @if($totalSaleTax > 0)
                                    <td class="align-middle">
                                        {{ format_currency($detail->sub_total - $detail->product_tax_amount - $detail->product_discount_amount) }}
                                    </td>
                                    <td class="align-middle">
                                        {{ $detail->tax ? $detail->tax->value . '%' : '-' }}
                                    </td>
                                @endif
                                <td class="align-middle">{{ format_currency($detail->sub_total) }}</td>
                            </tr>

                            {{-- Tampilkan bundle items jika ada --}}
                            @if($detail->bundleItems->isNotEmpty())
                                <tr>
                                    <td colspan="{{ $totalSaleTax > 0 ? 7 : 5 }}">
                                        <div class="ms-4">
                                            <strong>Item Bundel:</strong>
                                            <table class="table table-sm table-bordered mt-2">
                                                <thead>
                                                <tr>
                                                    <th>Nama Bundel</th>
                                                    <th>Harga</th>
                                                    <th>Kuantitas</th>
                                                    <th>Jumlah</th>
                                                </tr>
                                                </thead>
                                                <tbody>
                                                @foreach($detail->bundleItems as $bundle)
                                                    <tr>
                                                        <td>{{ $bundle->name }}</td>
                                                        <td>{{ ($bundle->price > 0) ? format_currency($bundle->price) : '-' }}</td>
                                                        <td>{{ $bundle->quantity }}</td>
                                                        <td>{{ ($bundle->sub_total > 0) ? format_currency($bundle->sub_total) : '-' }}</td>
                                                    </tr>
                                                @endforeach
                                                </tbody>
                                            </table>
                                        </div>
                                    </td>
                                </tr>
                            @endif
                        @endforeach

                        {{-- Tampilkan standalone bundle items jika ada --}}
                        @php
                            $standaloneBundles = $sale->bundleItems->filter(fn($item) => is_null($item->sale_detail_id));
                        @endphp
                        @if($standaloneBundles->isNotEmpty())
                            <tr class="table-info">
                                <td colspan="{{ $totalSaleTax > 0 ? 7 : 5 }}">
                                    <strong>Item Layanan / Bundel Terpisah:</strong>
                                </td>
                            </tr>
                            @foreach($standaloneBundles as $bundle)
                                <tr>
                                    <td class="align-middle">
                                        {{ $bundle->name }} <br>
                                        <span class="badge bg-success">{{ $bundle->product->product_code ?? 'N/A' }}</span>
                                    </td>
                                    <td class="align-middle">{{ format_currency($bundle->price) }}</td>
                                    <td class="align-middle">{{ $bundle->quantity }}</td>
                                    <td class="align-middle">-</td>
                                    @if($totalSaleTax > 0)
                                        <td class="align-middle">
                                            {{ format_currency($bundle->sub_total - $bundle->tax_amount) }}
                                        </td>
                                        <td class="align-middle">
                                            {{ $bundle->tax ? $bundle->tax->value . '%' : '-' }}
                                        </td>
                                    @endif
                                    <td class="align-middle">{{ format_currency($bundle->sub_total) }}</td>
                                </tr>
                            @endforeach
                        @endif
                        </tbody>
                    </table>
                </div>

                <!-- Ringkasan Total -->
                <div class="row">
                    <div class="col-lg-4 col-sm-5 ml-md-auto">
                        @php
                            $dppAmount = $totalSubTotal - $totalSaleTax - $sale->discount_amount;
                        @endphp
                        <table class="table">
                            <tbody>
                            @if($totalSaleTax > 0)
                                <tr>
                                    <td class="left"><strong>DPP (Dasar Pengenaan Pajak)</strong></td>
                                    <td class="right">{{ format_currency($dppAmount) }}</td>
                                </tr>
                            @endif
                            <tr>
                                <td class="left"><strong>Diskon ({{ $sale->discount_percentage }}%)</strong></td>
                                <td class="right">{{ format_currency($sale->discount_amount) }}</td>
                            </tr>
                            @if($totalSaleTax > 0)
                                <tr>
                                    <td class="left"><strong>Pajak</strong></td>
                                    <td class="right">{{ format_currency($totalSaleTax) }}</td>
                                </tr>
                            @endif
                            <tr>
                                <td class="left"><strong>Pengiriman</strong></td>
                                <td class="right">{{ format_currency($sale->shipping_amount) }}</td>
                            </tr>
                            <tr>
                                <td class="left"><strong>Total Keseluruhan</strong></td>
                                <td class="right"><strong>{{ format_currency($sale->total_amount) }}</strong></td>
                            </tr>
                            </tbody>
                        </table>
                    </div>
                </div>

            <!-- Catatan -->
            <div class="row mt-4">
                <div class="col-sm-12">
                    <h5 class="mb-2 border-bottom pb-2">Catatan:</h5>
                    <p>{{ $sale->note ?? 'Tidak ada catatan.' }}</p>
                </div>
            </div>

            @php $attachments = $sale->getMedia('attachments'); @endphp
            <div class="row mt-4">
                <div class="col-sm-12">
                    <h5 class="mb-2 border-bottom pb-2">Lampiran:</h5>
                    @can('sales.edit')
                        @if(!$sale->isArchived())
                            <form action="{{ route('sales.attachments.store', $sale->id) }}"
                              method="POST"
                              enctype="multipart/form-data"
                              class="mb-3">
                            @csrf
                            <div class="form-group mb-2">
                                <label for="sale-attachment" class="font-weight-bold">Tambah Lampiran</label>
                                <div class="attachment-uploader">
                                    <div class="attachment-uploader__icon">
                                        <i class="bi bi-paperclip"></i>
                                    </div>
                                    <div class="attachment-uploader__body">
                                        <div class="custom-file">
                                            <input type="file"
                                                   name="file"
                                                   id="sale-attachment"
                                                   class="custom-file-input @error('file') is-invalid @enderror">
                                            <label class="custom-file-label" for="sale-attachment">Pilih file...</label>
                                            @error('file')
                                            <div class="invalid-feedback">{{ $message }}</div>
                                            @enderror
                                        </div>
                                        <small class="form-text text-muted">
                                            Maksimal 1 lampiran per unggah (10MB).
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
                                    $isImage = \Illuminate\Support\Str::startsWith($mimeType, 'image/');
                                @endphp
                                <li class="list-group-item d-flex flex-wrap justify-content-between align-items-center">
                                    <div>
                                        <div>{{ $displayName }}</div>
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
                                        @can('sales.edit')
                                            @if(!$sale->isArchived())
                                                <form method="POST"
                                                      action="{{ route('sales.attachments.destroy', [$sale->id, $media->id]) }}"
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
        </div>

            <!-- Dispatch Details -->
            @if($dispatches->isNotEmpty())
                <div class="row mt-4">
                    <div class="col-lg-12">
                        <div class="card">
                            <div class="card-body">
                                <h4 class="mb-3">Pengeluaran Barang</h4>
                                <div class="mb-3">
                                    <span class="badge bg-info me-1">Biru</span>
                                    <small class="me-3">Serial masih aktif pada penjualan ini</small>
                                    <span class="badge bg-danger me-1">Merah</span>
                                    <small class="me-3">Serial sudah diretur dari penjualan ini</small>
                                    <span class="badge bg-secondary me-1">Abu-abu</span>
                                    <small>Status pengiriman belum final / serial tidak ditemukan</small>
                                </div>
                                <div class="table-responsive">
                                    <table id="sale-dispatches-table" class="table table-striped table-bordered">
                                        <thead>
                                        <tr>
                                            <th></th> {{-- expand --}}
                                            <th>Tanggal</th>
                                            <th>Total Dikirim</th>
                                            <th>Status</th>
                                            <th class="d-print-none">Aksi</th>
                                        </tr>
                                        </thead>
                                        <tbody>
                                        @foreach($dispatches as $dispatch)
                                            @php $sumQty = $dispatch->details->sum('dispatched_quantity'); @endphp
                                            <tr>
                                                <td class="text-center">
                                                    <button class="btn btn-sm btn-outline-primary toggle-details"
                                                            data-details-target="dispatch-{{ $dispatch->id }}"
                                                            aria-expanded="false"
                                                            aria-controls="dispatch-{{ $dispatch->id }}">
                                                        <i class="bi bi-plus-circle"></i>
                                                    </button>
                                                </td>
                                                <td>{{ \Carbon\Carbon::parse($dispatch->dispatch_date)->format('Y-m-d') }}</td>
                                                <td>{{ $sumQty }}</td>
                                                <td>
                                                    @if($dispatch->isPending())
                                                        <span class="badge badge-warning">Menunggu Persetujuan</span>
                                                    @elseif($dispatch->isApproved())
                                                        <span class="badge badge-success">Disetujui</span>
                                                    @elseif($dispatch->isRejected())
                                                        <span class="badge badge-danger">Ditolak</span>
                                                        @if($dispatch->rejection_reason)
                                                            <i class="bi bi-info-circle text-danger" title="{{ $dispatch->rejection_reason }}"></i>
                                                        @endif
                                                    @endif
                                                </td>
                                                <td class="d-print-none">
                                                    @if($dispatch->isPending())
                                                        @can('salesDispatches.approval')
                                                            <div class="btn-group">
                                                                <form action="{{ route('dispatches.approve', $dispatch->id) }}" method="POST" class="d-inline" data-dispatch-approval-id="{{ $dispatch->id }}">
                                                                    @csrf
                                                                    <button type="submit" class="btn btn-sm btn-success js-dispatch-approve-btn" title="Setujui Pengiriman">
                                                                        <i class="bi bi-check-circle"></i>
                                                                    </button>
                                                                </form>
                                                                <button type="button" class="btn btn-sm btn-danger ms-1" data-toggle="modal" data-target="#rejectDispatch{{ $dispatch->id }}">
                                                                    <i class="bi bi-x-circle"></i>
                                                                </button>
                                                            </div>

                                                            {{-- Reject Modal --}}
                                                            <div class="modal fade" id="rejectDispatch{{ $dispatch->id }}" tabindex="-1" role="dialog" aria-labelledby="rejectDispatchLabel{{ $dispatch->id }}" aria-hidden="true">
                                                                <div class="modal-dialog" role="document">
                                                                    <div class="modal-content">
                                                                        <form action="{{ route('dispatches.reject', $dispatch->id) }}" method="POST">
                                                                            @csrf
                                                                            <div class="modal-header">
                                                                                <h5 class="modal-title" id="rejectDispatchLabel{{ $dispatch->id }}">Tolak Pengiriman</h5>
                                                                                <button type="button" class="close" data-dismiss="modal" aria-label="Close">
                                                                                    <span aria-hidden="true">&times;</span>
                                                                                </button>
                                                                            </div>
                                                                            <div class="modal-body">
                                                                                <div class="form-group">
                                                                                    <label for="rejection_reason">Alasan Penolakan</label>
                                                                                    <textarea name="rejection_reason" id="rejection_reason" class="form-control" rows="3" required></textarea>
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
                                                        @else
                                                            -
                                                        @endcan
                                                    @else
                                                        -
                                                    @endif
                                                </td>
                                            </tr>

                                            <tr id="dispatch-{{ $dispatch->id }}" class="dispatch-details-row d-none">
                                                <td colspan="5">
                                                    <div class="table-responsive">
                                                        <table class="table table-sm table-bordered">
                                                            <thead>
                                                            <tr>
                                                                <th>Kode Produk</th>
                                                                <th>Nama Produk</th>
                                                                <th>Lokasi</th>
                                                                <th>Jumlah</th>
                                                                <th>Serial Number</th>
                                                            </tr>
                                                            </thead>
                                                            <tbody>
                                                            @foreach($dispatch->details as $detail)
                                                                <tr>
                                                                    <td>{{ $detail->product->product_code ?? '-' }}</td>
                                                                    <td>{{ $detail->product->product_name ?? '-' }}</td>
                                                                    <td>{{ $detail->location->name ?? '-' }}</td>
                                                                    <td>{{ $detail->dispatched_quantity }}</td>
                                                                    <td>
                                                                        @if(!empty($detail->serialNumberBadges))
                                                                            <div class="d-flex flex-wrap">
                                                                                @foreach($detail->serialNumberBadges as $serialBadge)
                                                                                    <span class="badge {{ $serialBadge['badge_class'] }} me-1 mb-1"
                                                                                          title="{{ $serialBadge['title'] }}">
                                                                                        {{ $serialBadge['serial_number'] }}
                                                                                    </span>
                                                                                @endforeach
                                                                            </div>
                                                                        @elseif($detail->serial_numbers)
                                                                            {{ implode(', ', json_decode($detail->serial_numbers, true) ?: []) }}
                                                                        @else
                                                                            -
                                                                        @endif
                                                                    </td>
                                                                </tr>
                                                            @endforeach
                                                            </tbody>
                                                        </table>
                                                    </div>
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
            @endif

            <div class="row mt-4">
                <div class="col-lg-12">
                    <div class="card">
                        <div class="card-body">
                            <h4 class="mb-3">Pembayaran</h4>

                            {{-- Yajra DataTable --}}
                            {!! $dataTable->table(['class' => 'table table-striped table-bordered w-100', 'id' => 'sale-payments-table']) !!}
                        </div>
                    </div>
                </div>
            </div>

            @if(!$sale->isArchived())
            <div class="card-footer text-end">
                @if ($sale->status === Sale::STATUS_DRAFTED)
                    <form method="POST" action="{{ route('sales.updateStatus', $sale->id) }}" class="d-inline">
                        @csrf
                        @method('PATCH')
                        <input type="hidden" name="status" value="{{ Sale::STATUS_WAITING_APPROVAL }}">
                        <button type="submit" class="btn btn-warning">Kirim untuk Persetujuan</button>
                    </form>
                    <a href="{{ route('sales.edit', $sale->id) }}" class="btn btn-primary">
                        <i class="bi bi-pencil mr-2"></i> Ubah
                    </a>
                @endif

                @can('sales.approval')
                    @if ($sale->status === Sale::STATUS_WAITING_APPROVAL)
                        <form method="POST" action="{{ route('sales.updateStatus', $sale->id) }}" class="d-inline" data-sale-approval-id="{{ $sale->id }}" data-status="{{ Sale::STATUS_APPROVED }}">
                            @csrf
                            @method('PATCH')
                            <input type="hidden" name="status" value="{{ Sale::STATUS_APPROVED }}">
                            <button type="submit" class="btn btn-success">Setuju</button>
                        </form>
                        <form method="POST" action="{{ route('sales.updateStatus', $sale->id) }}" class="d-inline">
                            @csrf
                            @method('PATCH')
                            <input type="hidden" name="status" value="{{ Sale::STATUS_REJECTED }}">
                            <button type="submit" class="btn btn-danger">Tolak</button>
                        </form>
                    @endif
                @endcan

                @can('sales.dispatch')
                    @if ($sale->status === Sale::STATUS_APPROVED || $sale->status === Sale::STATUS_DISPATCHED_PARTIALLY)
                        <a href="{{ route('sales.dispatch', $sale->id) }}" class="btn btn-primary">
                            Keluarkan
                        </a>
                    @endif
                @endcan

                @can('overrideReportingDate', $sale)
                    <button type="button" id="reportingDateOverrideButton" class="btn btn-secondary" data-toggle="modal" data-target="#reportingDateOverrideModal">
                        <i class="bi bi-calendar-event mr-2"></i> Ubah Tanggal Pelaporan
                    </button>
                @endcan
            </div>
            @endif
        </div>

        {{-- Reporting Date Override Modal (rendered only with permission) --}}
        @can('overrideReportingDate', $sale)
        {{-- Reporting Date Audit History --}}
        @if($sale->reportingDateAudits->isNotEmpty() || $sale->reporting_date)
        <div class="card mt-4">
            <div class="card-header">
                <h5 class="mb-0">Riwayat Perubahan Tanggal Pelaporan</h5>
            </div>
            <div class="card-body">
                <div class="table-responsive">
                    <table class="table table-sm table-hover">
                        <thead>
                            <tr>
                                <th>Tanggal Asli</th>
                                <th>Tanggal Sebelumnya</th>
                                <th>Tanggal Saat Ini</th>
                                <th>Alasan</th>
                                <th>Petugas</th>
                                <th>Waktu</th>
                            </tr>
                        </thead>
                        <tbody>
                            @forelse($sale->reportingDateAudits->sortByDesc('id') as $audit)
                                <tr>
                                    <td>{{ $audit->original_date ? Carbon::parse($audit->original_date)->format('d M, Y') : '-' }}</td>
                                    <td>{{ $audit->prior_override ? Carbon::parse($audit->prior_override)->format('d M, Y') : '-' }}</td>
                                    <td>{{ $audit->resulting_override ? Carbon::parse($audit->resulting_override)->format('d M, Y') : '-' }}</td>
                                    <td>{{ $audit->reason }}</td>
                                    <td>{{ $audit->actor->name ?? '-' }}</td>
                                    <td>{{ $audit->created_at->format('d M, Y H:i') }}</td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="6" class="text-center text-muted">Belum ada perubahan tanggal pelaporan</td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
        @endif
    </div>
        {{-- Reporting Date Override Modal --}}
        <div class="modal fade" id="reportingDateOverrideModal" tabindex="-1" role="dialog" aria-labelledby="reportingDateOverrideModalLabel" aria-hidden="true">
            <div class="modal-dialog" role="document">
                <div class="modal-content">
                    <div class="modal-header">
                        <h5 class="modal-title" id="reportingDateOverrideModalLabel">Ubah Tanggal Pelaporan</h5>
                        <button type="button" class="close" data-dismiss="modal" aria-label="Close">
                            <span aria-hidden="true">&times;</span>
                        </button>
                    </div>
                    <div class="modal-body">
                        <div id="reportingDateErrorAlert" class="alert alert-danger d-none" role="alert"></div>

                        <div class="alert alert-info small mb-3">
                            <strong>Tanggal Dokumen Asli:</strong> {{ Carbon::parse($sale->date)->format('d M, Y') }}<br>
                            @if($sale->reporting_date)
                                <strong>Tanggal Pelaporan Saat Ini:</strong> {{ Carbon::parse($sale->reporting_date)->format('d M, Y') }}
                            @else
                                <strong>Tanggal Pelaporan Saat Ini:</strong> -
                            @endif
                        </div>

                        <!-- Create/Replace Tab -->
                        <div class="reportingDateMode" id="createOverrideMode">
                            <form id="reportingDateOverrideForm">
                                @csrf
                                <div class="form-group">
                                    <label for="reporting_date">Tanggal Pelaporan <span class="text-danger">*</span></label>
                                    <input type="date" id="reporting_date" name="reporting_date" class="form-control" value="{{ $sale->reporting_date ? $sale->reporting_date->format('Y-m-d') : '' }}" required>
                                    <small class="form-text text-muted">Tanggal pelaporan bisa berupa tanggal lampau, sekarang, atau masa depan</small>
                                </div>
                                <div class="form-group">
                                    <label for="reason">Alasan <span class="text-danger">*</span></label>
                                    <textarea id="reason" name="reason" class="form-control" rows="3" required placeholder="Masukkan alasan perubahan tanggal pelaporan..."></textarea>
                                </div>
                                <button type="submit" class="btn btn-primary btn-block">Simpan Tanggal Pelaporan</button>
                            </form>
                        </div>

                        <!-- Clear Tab -->
                        @if($sale->reporting_date)
                        <div class="reportingDateMode d-none mt-3 pt-3 border-top" id="clearOverrideMode">
                            <form id="clearReportingDateForm">
                                @csrf
                                <p class="text-muted mb-3">Untuk menghapus override tanggal pelaporan, masukkan alasan:</p>
                                <div class="form-group">
                                    <label for="clearReason">Alasan Penghapusan <span class="text-danger">*</span></label>
                                    <textarea id="clearReason" name="reason" class="form-control" rows="3" required placeholder="Masukkan alasan penghapusan override..."></textarea>
                                </div>
                                <button type="submit" class="btn btn-outline-danger btn-block">Hapus Override</button>
                            </form>
                        </div>
                        @endif
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-dismiss="modal">Batal</button>
                        @if($sale->reporting_date)
                        <button type="button" class="btn btn-outline-warning" id="toggleClearMode">Tampilkan Mode Hapus</button>
                        @endif
                    </div>
                </div>
            </div>
        </div>
        @endcan
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
        document.addEventListener('DOMContentLoaded', function () {
            var input = document.getElementById('sale-attachment');
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
    {{-- Toggle buttons for dispatch detail rows without Bootstrap collapse --}}
    <script>
        (function () {
            function initDispatchToggle() {
                const table = document.getElementById('sale-dispatches-table');
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
            }

            if (document.readyState === 'loading') {
                document.addEventListener('DOMContentLoaded', initDispatchToggle);
            } else {
                initDispatchToggle();
            }
        })();
    </script>

    {{-- Yajra DataTables scripts for payments --}}
    {!! $dataTable->scripts() !!}

    <script>
        document.addEventListener('DOMContentLoaded', function () {
            const overrideForm = document.getElementById('reportingDateOverrideForm');
            const clearForm = document.getElementById('clearReportingDateForm');
            const errorAlert = document.getElementById('reportingDateErrorAlert');
            const toggleClearBtn = document.getElementById('toggleClearMode');
            const createMode = document.getElementById('createOverrideMode');
            const clearMode = document.getElementById('clearOverrideMode');

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

                    fetch('{{ route("sales.reporting-date.store", $sale->id) }}', {
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

                    fetch('{{ route("sales.reporting-date.destroy", $sale->id) }}', {
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

            document.querySelectorAll('.js-dispatch-approve-btn').forEach(function (btn) {
                btn.addEventListener('click', async function (e) {
                    e.preventDefault();
                    const form = this.closest('form');
                    if (typeof Swal !== 'undefined') {
                        const result = await Swal.fire({
                            icon: 'question',
                            title: 'Konfirmasi Persetujuan',
                            text: 'Setujui pengiriman ini? Stok akan dikurangi.',
                            showCancelButton: true,
                            confirmButtonText: 'Setujui',
                            cancelButtonText: 'Batal',
                            confirmButtonColor: '#28a745',
                        });
                        if (result.isConfirmed) {
                            form.submit();
                        }
                    } else {
                        console.error('SweetAlert is not available for approval confirmation.');
                    }
                });
            });
        });
    </script>
    @include('sale::partials.lifecycle-warning-modal')
@endpush

<div>
    @if (session()->has('message'))
        <div class="alert alert-warning alert-dismissible fade show" role="alert">
            <div class="alert-body">
                <span>{{ session('message') }}</span>
                <button type="button" class="close" data-dismiss="alert" aria-label="Close">
                    <span aria-hidden="true">×</span>
                </button>
            </div>
        </div>
    @endif

    @if ($feedbackMessage)
        <div class="alert alert-{{ $feedbackType }} alert-dismissible fade show mb-3" role="alert">
            <div class="alert-body d-flex align-items-center justify-content-between">
                <span>
                    @if($feedbackType === 'success') <i class="bi bi-check-circle-fill mr-1"></i>
                    @elseif($feedbackType === 'danger') <i class="bi bi-x-circle-fill mr-1"></i>
                    @elseif($feedbackType === 'warning') <i class="bi bi-exclamation-triangle-fill mr-1"></i>
                    @else <i class="bi bi-info-circle-fill mr-1"></i>
                    @endif
                    {{ $feedbackMessage }}
                </span>
                <button type="button" class="close" wire:click="$set('feedbackMessage', null)" aria-label="Close">
                    <span aria-hidden="true">×</span>
                </button>
            </div>
        </div>
    @endif

    {{-- Scan Bar --}}
    <div class="card bg-light border mb-3 shadow-none">
        <div class="card-body py-3 px-3">
            <div class="row align-items-center">
                <div class="col-md-10 mb-2 mb-md-0">
                    <label class="font-weight-bold text-muted small text-uppercase mb-1 d-block">
                        Pindai Barcode / Nomor Seri Barang Bagus (Tekan Enter)
                    </label>
                    <div class="input-group">
                        <div class="input-group-prepend">
                            <span class="input-group-text bg-white border-right-0">
                                <i class="bi bi-upc-scan text-primary"></i>
                            </span>
                        </div>
                        <input type="text"
                               id="breakage-scan-input"
                               class="form-control border-left-0"
                               placeholder="Pindai barcode produk, barcode konversi, atau nomor seri..."
                               wire:model="scanInput"
                               wire:keydown.enter.prevent="processScan"
                               {{ !$locationId ? 'disabled' : '' }}
                               autofocus>
                        <div class="input-group-append">
                            <button type="button" class="btn btn-primary" wire:click="processScan" {{ !$locationId ? 'disabled' : '' }}>
                                <i class="bi bi-arrow-return-left"></i> Pindai
                            </button>
                        </div>
                    </div>
                </div>
                <div class="col-md-2 text-md-right">
                    <label class="font-weight-bold text-muted small text-uppercase mb-1 d-block">&nbsp;</label>
                    <button type="button" class="btn btn-outline-secondary btn-block" wire:click="openSearchModal" {{ !$locationId ? 'disabled' : '' }}>
                        <i class="bi bi-search"></i> Cari Produk
                    </button>
                </div>
            </div>
        </div>
    </div>

    {{-- Hidden fields for form submission --}}
    <input type="hidden" name="location_id" value="{{ $locationId }}">
    @foreach($products as $key => $product)
        <input type="hidden" name="product_ids[]" value="{{ $product['id'] }}">
        <input type="hidden" name="quantities_tax[{{ $key }}]" value="{{ $this->wireQuantitiesTax[$key] ?? 0 }}">
        <input type="hidden" name="quantities_non_tax[{{ $key }}]" value="{{ $this->wireQuantitiesNonTax[$key] ?? 0 }}">
        @foreach($product['serial_numbers'] ?? [] as $serialNumber)
            <input type="hidden" name="serial_numbers[{{ $key }}][]" value="{{ $serialNumber['id'] }}">
        @endforeach
    @endforeach

    <div class="table-responsive border rounded bg-white">
        <div wire:loading.flex class="col-12 position-absolute justify-content-center align-items-center" style="top:0;right:0;left:0;bottom:0;background-color: rgba(255,255,255,0.5);z-index: 99;">
            <div class="spinner-border text-primary" role="status">
                <span class="sr-only">Memuat...</span>
            </div>
        </div>

        <table class="table table-hover table-striped mb-0">
            <thead class="thead-light">
            <tr class="align-middle text-center small text-uppercase">
                <th style="width: 4%;">No</th>
                <th class="text-left" style="width: 30%;">Produk & Satuan Dasar</th>
                <th style="width: 16%;">Stok Baik Tersedia</th>
                <th style="width: 18%;">Kuantitas Rusak</th>
                <th style="width: 16%;">Stok Rusak Saat Ini</th>
                <th style="width: 16%;">Aksi</th>
            </tr>
            </thead>
            <tbody>
            @if(!empty($products))
                @foreach($products as $key => $product)
                    @php
                        $isSerialized = !empty($product['serial_number_required']);
                        $qty = (int) ($quantities[$key] ?? 0);
                        $available = (int) ($product['available_good'] ?? 0);
                        $currentBad = ($product['broken_quantity_tax'] ?? 0) + ($product['broken_quantity_non_tax'] ?? 0);
                    @endphp
                    <tr class="align-middle">
                        <td class="align-middle text-center">{{ $key + 1 }}</td>
                        <td class="align-middle">
                            <div class="font-weight-bold">{{ $product['product_name'] }}</div>
                            <div class="text-muted small">
                                <code>{{ $product['product_code'] }}</code>
                                <span class="badge badge-secondary ml-1">{{ $product['unit'] }}</span>
                                @if($isSerialized)
                                    <span class="badge badge-info ml-1"><i class="bi bi-upc"></i> Nomor Seri</span>
                                @endif
                            </div>
                        </td>
                        <td class="align-middle text-center">
                            <span class="badge badge-pill badge-light border text-dark">
                                <i class="bi bi-shield-check text-success"></i> {{ $available }} {{ $product['unit'] }}
                            </span>
                        </td>
                        <td class="align-middle text-center">
                            @if($isSerialized)
                                <div class="input-group input-group-sm justify-content-center">
                                    <input type="text" class="form-control text-center font-weight-bold text-danger bg-light" style="max-width: 90px;"
                                           value="{{ $qty }}" readonly>
                                    <div class="input-group-append">
                                        <button type="button" class="btn btn-outline-danger"
                                                wire:click="openSerialModal({{ $key }})"
                                                title="Kelola Nomor Seri">
                                            <i class="bi bi-list-ol"></i>
                                        </button>
                                    </div>
                                </div>
                            @else
                                <input type="number"
                                       class="form-control form-control-sm text-center font-weight-bold text-danger"
                                       wire:model.lazy="quantities.{{ $key }}"
                                       wire:keydown.enter.prevent
                                       inputmode="numeric" min="0" max="{{ $available }}">
                            @endif
                            @error("quantities_tax.{$key}")
                                <div class="text-danger small mt-1">{{ $message }}</div>
                            @enderror
                            @if (!empty($serialNumberErrors[$key]))
                                <div class="text-danger small mt-1">{{ $serialNumberErrors[$key] }}</div>
                            @endif
                        </td>
                        <td class="align-middle text-center">
                            <span class="badge badge-pill badge-light border text-dark">
                                <i class="bi bi-shield-x text-danger"></i> {{ $currentBad }} {{ $product['unit'] }}
                            </span>
                        </td>
                        <td class="align-middle text-center">
                            @if($isSerialized)
                                <button type="button" class="btn btn-sm btn-info mr-1"
                                        wire:click="openSerialModal({{ $key }})"
                                        title="Kelola Nomor Seri">
                                    <i class="bi bi-upc"></i> Nomor Seri ({{ count($product['serial_numbers'] ?? []) }})
                                </button>
                            @endif
                            <button type="button" class="btn btn-sm btn-outline-danger" wire:click="removeProduct({{ $key }})">
                                <i class="bi bi-trash"></i>
                            </button>
                        </td>
                    </tr>
                @endforeach
            @else
                <tr>
                    <td colspan="6" class="text-center py-5 text-muted">
                        <i class="bi bi-box-seam display-4 d-block mb-2 text-secondary"></i>
                        <span class="font-weight-bold">Belum ada produk yang ditambahkan.</span><br>
                        <small>Pindai barcode di atas atau klik "Cari Produk" untuk memulai pencatatan barang rusak.</small>
                    </td>
                </tr>
            @endif
            </tbody>
        </table>
    </div>

    {{-- Modal: Product Search Dialog --}}
    @if($showSearchModal)
        <div class="modal fade show d-block" tabindex="-1" style="background: rgba(0,0,0,0.5); z-index: 1060;">
            <div class="modal-dialog modal-lg">
                <div class="modal-content">
                    <div class="modal-header">
                        <h5 class="modal-title"><i class="bi bi-search mr-1"></i> Cari Produk (Stok Dikelola)</h5>
                        <button type="button" class="close" wire:click="closeSearchModal">
                            <span>&times;</span>
                        </button>
                    </div>
                    <div class="modal-body">
                        <div class="form-group">
                            <input type="text" class="form-control form-control-lg"
                                   placeholder="Ketik nama, kode, atau barcode produk..."
                                   wire:model.live.debounce.300ms="searchTerm"
                                   wire:keydown.enter.prevent="searchProducts"
                                   autofocus>
                        </div>

                        <div class="list-group list-group-flush border rounded" style="max-height: 350px; overflow-y: auto;">
                            @forelse($searchResults as $result)
                                <button type="button" class="list-group-item list-group-item-action d-flex justify-content-between align-items-center"
                                        wire:click="productSelected({{ json_encode($result) }})"
                                        wire:loading.attr="disabled"
                                        wire:target="productSelected">
                                    <div>
                                        <div class="font-weight-bold">{{ $result['product_name'] }}</div>
                                        <small class="text-muted">
                                            Kode: <code>{{ $result['product_code'] }}</code> | Barcode: <code>{{ $result['barcode'] ?? '-' }}</code> | Satuan: {{ $result['base_unit'] }}
                                        </small>
                                    </div>
                                    <div>
                                        @if($result['serial_number_required'])
                                            <span class="badge badge-info mr-2">Nomor Seri</span>
                                        @endif
                                        <span class="btn btn-sm btn-primary">Pilih</span>
                                    </div>
                                </button>
                            @empty
                                <div class="text-center py-4 text-muted">
                                    @if(trim($searchTerm) === '')
                                        <span>Ketik kata kunci untuk mencari produk.</span>
                                    @else
                                        <span>Produk tidak ditemukan.</span>
                                    @endif
                                </div>
                            @endforelse
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" wire:click="closeSearchModal">Tutup</button>
                    </div>
                </div>
            </div>
        </div>
    @endif

    {{-- Modal: Ambiguity Choice Dialog --}}
    @if($showAmbiguityModal)
        <div class="modal fade show d-block" tabindex="-1" style="background: rgba(0,0,0,0.5); z-index: 1060;">
            <div class="modal-dialog modal-lg">
                <div class="modal-content">
                    <div class="modal-header bg-warning text-dark">
                        <h5 class="modal-title font-weight-bold">
                            <i class="bi bi-question-circle-fill mr-1"></i> Barcode Terdeteksi Ganda (Ambigu)
                        </h5>
                        <button type="button" class="close" wire:click="closeAmbiguityModal">
                            <span>&times;</span>
                        </button>
                    </div>
                    <div class="modal-body">
                        <p class="text-muted">
                            Kode barcode yang dipindai cocok dengan beberapa interpretasi berikut. Silahkan pilih item yang Anda maksud:
                        </p>
                        <div class="list-group">
                            @foreach($ambiguousCandidates as $candIndex => $candidate)
                                <button type="button" class="list-group-item list-group-item-action d-flex justify-content-between align-items-center"
                                        wire:click="selectAmbiguousCandidate({{ $candIndex }})">
                                    <div>
                                        @php
                                            $typeBadge = match($candidate['type'] ?? '') {
                                                'product' => 'PRODUK',
                                                'conversion' => 'KONVERSI',
                                                'serial' => 'NOMOR SERI',
                                                default => strtoupper($candidate['type'] ?? ''),
                                            };
                                        @endphp
                                        <span class="badge badge-primary mr-2">{{ $typeBadge }}</span>
                                        <span class="font-weight-bold">{{ $candidate['description'] }}</span>
                                    </div>
                                    <span class="btn btn-sm btn-outline-primary">Pilih Item Ini</span>
                                </button>
                            @endforeach
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" wire:click="closeAmbiguityModal">Batal</button>
                    </div>
                </div>
            </div>
        </div>
    @endif

    {{-- Modal: Row Serial Dialog --}}
    @if($showSerialModal && $selectedRowIndexForSerials !== null && isset($products[$selectedRowIndexForSerials]))
        @php
            $modalProduct = $products[$selectedRowIndexForSerials];
            $rowSerials = $modalProduct['serial_numbers'] ?? [];
        @endphp
        <div class="modal fade show d-block" tabindex="-1" style="background: rgba(0,0,0,0.5); z-index: 1060;">
            <div class="modal-dialog modal-lg">
                <div class="modal-content">
                    <div class="modal-header">
                        <div>
                            <h5 class="modal-title font-weight-bold">
                                <i class="bi bi-upc-scan mr-1 text-primary"></i> Kelola Nomor Seri: {{ $modalProduct['product_name'] }}
                            </h5>
                            <small class="text-muted">Kode: {{ $modalProduct['product_code'] }} | Satuan: {{ $modalProduct['unit'] }}</small>
                        </div>
                        <button type="button" class="close" wire:click="closeSerialModal">
                            <span>&times;</span>
                        </button>
                    </div>
                    <div class="modal-body">
                        <div class="card bg-light border p-3 mb-3">
                            <label class="small text-muted font-weight-bold mb-1">
                                Pindai / Ketik Nomor Seri Barang Bagus (Tekan Enter)
                            </label>
                            <div class="input-group">
                                <input type="text"
                                       class="form-control"
                                       placeholder="Nomor seri harus sudah terdaftar sebagai barang bagus di lokasi ini..."
                                       wire:model="rowSerialInput"
                                       wire:keydown.enter.prevent="addRowSerial"
                                       autofocus>
                                <div class="input-group-append">
                                    <button type="button" class="btn btn-primary" wire:click="addRowSerial">
                                        <i class="bi bi-plus-circle"></i> Tambah
                                    </button>
                                </div>
                            </div>
                            @if($rowSerialError)
                                <span class="text-danger small font-weight-bold mt-1 d-block">{{ $rowSerialError }}</span>
                            @endif
                        </div>

                        <div class="d-flex justify-content-between align-items-center mb-2">
                            <span class="font-weight-bold">
                                Nomor Seri Rusak Tercatat ({{ count($rowSerials) }})
                            </span>
                        </div>

                        <div class="table-responsive border rounded" style="max-height: 300px; overflow-y: auto;">
                            <table class="table table-sm table-hover mb-0">
                                <thead class="thead-light">
                                <tr class="text-center small">
                                    <th style="width: 8%;">No</th>
                                    <th class="text-left" style="width: 72%;">Nomor Seri</th>
                                    <th style="width: 20%;">Aksi</th>
                                </tr>
                                </thead>
                                <tbody>
                                @forelse($rowSerials as $sIdx => $serial)
                                    <tr class="align-middle text-center">
                                        <td class="align-middle">{{ $sIdx + 1 }}</td>
                                        <td class="align-middle text-left font-weight-bold">
                                            <code>{{ $serial['serial_number'] }}</code>
                                        </td>
                                        <td class="align-middle">
                                            <button type="button" class="btn btn-sm btn-outline-danger"
                                                    wire:click="removeRowSerial({{ $sIdx }})">
                                                <i class="bi bi-trash"></i>
                                            </button>
                                        </td>
                                    </tr>
                                @empty
                                    <tr>
                                        <td colspan="3" class="text-center py-3 text-muted">
                                            Belum ada nomor seri yang dimasukkan untuk produk ini.
                                        </td>
                                    </tr>
                                @endforelse
                                </tbody>
                            </table>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-primary" wire:click="closeSerialModal">
                            Selesai & Tutup
                        </button>
                    </div>
                </div>
            </div>
        </div>
    @endif

    {{-- Modal: Confirm Location Change --}}
    @if($showLocationConfirmModal)
        <div class="modal fade show d-block" tabindex="-1" style="background: rgba(0,0,0,0.5); z-index: 1060;">
            <div class="modal-dialog">
                <div class="modal-content">
                    <div class="modal-header bg-danger text-white">
                        <h5 class="modal-title font-weight-bold">
                            <i class="bi bi-exclamation-triangle-fill mr-1"></i> Konfirmasi Perubahan Lokasi
                        </h5>
                    </div>
                    <div class="modal-body">
                        <p class="mb-0">
                            Mengubah lokasi akan <strong>menghapus seluruh produk dan kuantitas</strong> yang telah Anda masukkan pada daftar saat ini. Apakah Anda yakin ingin mengganti lokasi?
                        </p>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" wire:click="cancelLocationChange">
                            Batal
                        </button>
                        <button type="button" class="btn btn-danger font-weight-bold" wire:click="confirmLocationChange">
                            Ya, Ganti Lokasi & Hapus Daftar
                        </button>
                    </div>
                </div>
            </div>
        </div>
    @endif

    <script>
        document.addEventListener('DOMContentLoaded', function () {
            window.addEventListener('restore-scanner-focus', function () {
                setTimeout(function () {
                    const scanInput = document.getElementById('breakage-scan-input');
                    if (scanInput) {
                        scanInput.focus();
                        if (typeof scanInput.select === 'function') {
                            scanInput.select();
                        }
                    }
                }, 50);
            });

            window.addEventListener('select-scan-input', function () {
                setTimeout(function () {
                    const scanInput = document.getElementById('breakage-scan-input');
                    if (scanInput) {
                        scanInput.focus();
                        if (typeof scanInput.select === 'function') {
                            scanInput.select();
                        }
                    }
                }, 50);
            });
        });
    </script>
</div>

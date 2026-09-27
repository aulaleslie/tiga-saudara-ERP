@extends('layouts.app')

@section('title', 'Konversi Stok ke Serial Number')

@section('breadcrumb')
    <ol class="breadcrumb border-0 m-0">
        <li class="breadcrumb-item"><a href="{{ route('home') }}">Beranda</a></li>
        <li class="breadcrumb-item"><a href="{{ route('products.index') }}">Produk</a></li>
        <li class="breadcrumb-item active">Konversi Seri</li>
    </ol>
@endsection

@section('content')
<div class="container-fluid" x-data="serialConversionApp()">
    <div class="row">
        <div class="col-12">
            <div class="card shadow-sm">
                <div class="card-header bg-primary text-white d-flex justify-content-between align-items-center">
                    <h5 class="mb-0"><i class="bi bi-upc-scan mr-2"></i> Konversi Stok Produk ke Serial Number</h5>
                    @if($product)
                        <span class="badge badge-light text-primary font-weight-bold">{{ $product->product_code }} - {{ $product->product_name }}</span>
                    @endif
                </div>

                <div class="card-body">
                    <!-- Product Selection Search Form -->
                    <form method="GET" action="{{ route('products.convert-to-serialized.show') }}" class="mb-4">
                        <div class="form-row align-items-end">
                            <div class="col-md-9 col-lg-8">
                                <label for="product_id" class="font-weight-bold">Cari Produk Non-Serial Memiliki Stok</label>
                                <livewire:modules.product.product-search-dropdown
                                    name="product_id"
                                    placeholder="Ketik nama atau kode produk..."
                                    :selected="$product?->id"
                                    :conversionCandidatesOnly="true"
                                />
                            </div>
                            <div class="col-md-3 col-lg-2 mt-2 mt-md-0">
                                <button type="submit" class="btn btn-primary btn-block">
                                    <i class="bi bi-search mr-1"></i> Pilih Produk
                                </button>
                            </div>
                        </div>
                    </form>

                    @if(! $product)
                        <div class="alert alert-info">
                            <i class="bi bi-info-circle mr-1"></i> Silakan pilih produk di atas untuk memulai proses konversi stok ke nomor seri.
                        </div>
                    @else
                        <!-- Ineligibility Warning -->
                        @if(! $eligibility->isEligible)
                            <div class="alert alert-warning">
                                <h6 class="font-weight-bold"><i class="bi bi-exclamation-triangle-fill mr-1"></i> Produk Tidak Dapat Dikonversi Saat Ini</h6>
                                
                                @if(!empty($eligibility->structuredBlockers))
                                    <div class="mb-3">
                                        <div class="font-weight-bold mb-2">Dokumen Aktif Memblokir Konversi:</div>
                                        <div class="list-group">
                                            @foreach($eligibility->structuredBlockers as $blocker)
                                                <div class="list-group-item list-group-item-warning flex-column align-items-start py-2">
                                                    <div class="d-flex w-100 justify-content-between align-items-center mb-1">
                                                        <span class="font-weight-bold">
                                                            <i class="bi bi-file-earmark-text mr-1"></i>
                                                            <span class="text-uppercase">{{ str_replace('_', ' ', $blocker['type']) }}</span>:
                                                            @if($blocker['can_view'] && $blocker['url'])
                                                                <a href="{{ $blocker['url'] }}" target="_blank" rel="noopener noreferrer" class="font-weight-bold text-primary">
                                                                    {{ $blocker['document_number'] }}
                                                                    <i class="bi bi-box-arrow-up-right small ml-1"></i>
                                                                </a>
                                                            @else
                                                                <span>{{ $blocker['document_number'] }}</span>
                                                            @endif
                                                        </span>
                                                        <span class="badge badge-secondary">{{ $blocker['status'] }}</span>
                                                    </div>
                                                    <p class="mb-1 text-dark">{{ $blocker['reason'] }}</p>
                                                    @if(! $blocker['can_view'])
                                                        <small class="text-muted italic"><i class="bi bi-lock mr-1"></i>Anda tidak memiliki izin untuk membuka dokumen ini.</small>
                                                    @endif
                                                </div>
                                            @endforeach
                                        </div>
                                    </div>
                                @endif

                                @if(!empty($eligibility->nonDocumentReasons))
                                    <ul class="mb-0 pl-3">
                                        @foreach($eligibility->nonDocumentReasons as $reason)
                                            <li>{{ $reason }}</li>
                                        @endforeach
                                    </ul>
                                @endif
                            </div>
                        @else
                            <!-- Eligibility Passed -> Main Scanner Interface -->
                            <div class="alert alert-info border-info">
                                <i class="bi bi-shield-check mr-1"></i>
                                <strong>Ketentuan Konversi:</strong> Pindai nomor seri untuk setiap unit stok (normal & rusak, PPN & Non-PPN) untuk seluruh lokasi cabang. Konversi dilakukan secara menyeluruh dan sekaligus dalam satu transaksi atomik.
                            </div>

                            <!-- Overall Progress Bar -->
                            <div class="card mb-4 bg-light">
                                <div class="card-body py-3">
                                    <div class="d-flex justify-content-between align-items-center mb-1">
                                        <span class="font-weight-bold">Total Kemajuan Pemindaian</span>
                                        <span class="badge badge-primary px-3 py-2 fs-6">
                                            <span x-text="totalScanned()"></span> / <span x-text="totalRequired()"></span> Unit
                                        </span>
                                    </div>
                                    <div class="progress" style="height: 20px;">
                                        <div class="progress-bar progress-bar-striped progress-bar-animated"
                                             role="progressbar"
                                             :class="totalScanned() === totalRequired() ? 'bg-success' : 'bg-primary'"
                                             :style="`width: ${progressPercentage()}%`"
                                             :aria-valuenow="progressPercentage()" aria-valuemin="0" aria-valuemax="100">
                                            <span x-text="`${progressPercentage()}%`" class="font-weight-bold"></span>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <!-- Pool Selector Controls -->
                            <div class="row mb-4">
                                <div class="col-md-4">
                                    <label class="font-weight-bold">1. Pilih Cabang / Owner</label>
                                    <select class="form-control" x-model.number="activeSettingId" @change="onPoolChanged()">
                                        <template x-for="(owner, id) in pools" :key="id">
                                            <option :value="id" x-text="owner.setting_name"></option>
                                        </template>
                                    </select>
                                </div>
                                <div class="col-md-4">
                                    <label class="font-weight-bold">2. Kondisi Stok</label>
                                    <div class="btn-group btn-group-toggle w-100" data-toggle="buttons">
                                        <label class="btn btn-outline-secondary" :class="{ 'active': activeCondition === 'normal' }">
                                            <input type="radio" value="normal" x-model="activeCondition" @change="onPoolChanged()"> Normal
                                        </label>
                                        <label class="btn btn-outline-warning" :class="{ 'active': activeCondition === 'broken' }">
                                            <input type="radio" value="broken" x-model="activeCondition" @change="onPoolChanged()"> Rusak (Broken)
                                        </label>
                                    </div>
                                </div>
                                <div class="col-md-4">
                                    <label class="font-weight-bold">3. Klasifikasi Pajak</label>
                                    <div class="btn-group btn-group-toggle w-100" data-toggle="buttons">
                                        <label class="btn btn-outline-secondary" :class="{ 'active': activeTax === 'non_tax' }">
                                            <input type="radio" value="non_tax" x-model="activeTax" @change="onPoolChanged()"> Non-PPN
                                        </label>
                                        <label class="btn btn-outline-info" :class="{ 'active': activeTax === 'tax' }">
                                            <input type="radio" value="tax" x-model="activeTax" @change="onPoolChanged()"> PPN
                                        </label>
                                    </div>
                                </div>
                            </div>

                            <!-- Active Pool Status Box & Scanner Input -->
                            <div class="card border-primary mb-4">
                                <div class="card-header bg-light d-flex justify-content-between align-items-center py-2">
                                    <span>
                                        <strong>Pool Aktif:</strong>
                                        <span class="badge badge-secondary" x-text="getActiveOwnerName()"></span> |
                                        <span class="badge badge-info" x-text="activeCondition === 'normal' ? 'Normal' : 'Rusak'"></span> |
                                        <span class="badge badge-dark" x-text="activeTax === 'non_tax' ? 'Non-PPN' : 'PPN'"></span>
                                    </span>
                                    <span class="badge" :class="getActivePoolScanned() === getActivePoolCapacity() ? 'badge-success' : 'badge-warning'">
                                        Kapasitas: <span x-text="getActivePoolScanned()"></span> / <span x-text="getActivePoolCapacity()"></span>
                                    </span>
                                </div>
                                <div class="card-body">
                                    <div class="input-group input-group-lg">
                                        <div class="input-group-prepend">
                                            <span class="input-group-text"><i class="bi bi-upc-scan"></i></span>
                                        </div>
                                        <input type="text"
                                               x-ref="scanInput"
                                               class="form-control"
                                               placeholder="Pindai / Ketik Serial Number lalu tekan Enter..."
                                               x-model="scanBuffer"
                                               @keydown.enter.prevent="handleScanSubmit()"
                                               :disabled="getActivePoolScanned() >= getActivePoolCapacity()"
                                        >
                                    </div>
                                    <small x-show="getActivePoolScanned() >= getActivePoolCapacity()" class="form-text text-success font-weight-bold mt-2">
                                        <i class="bi bi-check-circle-fill mr-1"></i> Pool aktif ini telah terisi penuh. Silakan pilih pool lain yang belum lengkap.
                                    </small>
                                    <div x-show="errorMessage" class="alert alert-danger mt-2 py-2 mb-0" x-text="errorMessage"></div>
                                </div>
                            </div>

                            <!-- Pool Badges Breakdown -->
                            <div class="card mb-4">
                                <div class="card-header bg-light font-weight-bold">
                                    <i class="bi bi-tags mr-1"></i> Rincian Serial Number Terpindai per Pool
                                </div>
                                <div class="card-body">
                                    <template x-for="(owner, settingId) in pools" :key="settingId">
                                        <div class="mb-3 p-3 border rounded">
                                            <h6 class="font-weight-bold border-bottom pb-2" x-text="owner.setting_name"></h6>
                                            <div class="row">
                                                <template x-for="poolKey in ['normal_non_tax', 'normal_tax', 'broken_non_tax', 'broken_tax']" :key="poolKey">
                                                    <div class="col-md-6 mb-3" x-show="owner.pools[poolKey] > 0">
                                                        <div class="d-flex justify-content-between align-items-center mb-1">
                                                            <small class="font-weight-bold text-uppercase" x-text="formatPoolKeyName(poolKey)"></small>
                                                            <small class="badge badge-secondary" x-text="`${scanned[settingId][poolKey].length} / ${owner.pools[poolKey]}`"></small>
                                                        </div>
                                                        <div class="d-flex flex-wrap gap-1 p-2 bg-light border rounded min-h-50">
                                                            <template x-for="(sn, idx) in scanned[settingId][poolKey]" :key="sn">
                                                                <span class="badge badge-primary p-2 mr-1 mb-1 d-inline-flex align-items-center">
                                                                    <span x-text="sn"></span>
                                                                    <button type="button" class="btn btn-sm text-white p-0 ml-2" style="line-height: 1;" @click="removeSerial(settingId, poolKey, sn)">
                                                                        &times;
                                                                    </button>
                                                                </span>
                                                            </template>
                                                            <span x-show="scanned[settingId][poolKey].length === 0" class="text-muted small italic">Belum ada scan</span>
                                                        </div>
                                                    </div>
                                                </template>
                                            </div>
                                        </div>
                                    </template>
                                </div>
                            </div>

                            <!-- Final Submit Action -->
                            <form id="final-conversion-form" method="POST" action="{{ route('products.convert-to-serialized.convert', $product->id) }}" @submit.prevent="submitConversion()">
                                @csrf
                                <input type="hidden" name="expected_pools_json" :value="JSON.stringify(pools)">
                                <input type="hidden" name="scanned_serials_json" :value="JSON.stringify(scanned)">

                                <div class="card border-success">
                                    <div class="card-body text-right">
                                        <button type="submit"
                                                class="btn btn-success btn-lg px-5 font-weight-bold"
                                                :disabled="totalScanned() !== totalRequired() || isSubmitting"
                                        >
                                            <i class="bi bi-check-circle-fill mr-2"></i>
                                            <span x-text="isSubmitting ? 'Memproses Konversi...' : 'Konfirmasi & Simpan Seluruh Seri Produk'"></span>
                                        </button>
                                    </div>
                                </div>
                            </form>
                        @endif
                    @endif
                </div>
            </div>
        </div>
    </div>
</div>

<script>
function serialConversionApp() {
    const rawPools = @json($pools ?? []);
    
    // Initialize scanned structure per setting and poolKey
    const initialScanned = {};
    Object.keys(rawPools).forEach(settingId => {
        initialScanned[settingId] = {
            normal_non_tax: [],
            normal_tax: [],
            broken_non_tax: [],
            broken_tax: []
        };
    });

    const settingIds = Object.keys(rawPools);

    return {
        pools: rawPools,
        scanned: initialScanned,
        activeSettingId: settingIds.length > 0 ? parseInt(settingIds[0]) : null,
        activeCondition: 'normal',
        activeTax: 'non_tax',
        scanBuffer: '',
        errorMessage: '',
        isSubmitting: false,

        init() {
            this.$nextTick(() => {
                if (this.$refs.scanInput) {
                    this.$refs.scanInput.focus();
                }
            });
        },

        getActivePoolKey() {
            return `${this.activeCondition}_${this.activeTax}`;
        },

        getActiveOwnerName() {
            return this.pools[this.activeSettingId] ? this.pools[this.activeSettingId].setting_name : '';
        },

        getActivePoolCapacity() {
            if (! this.pools[this.activeSettingId]) return 0;
            return this.pools[this.activeSettingId].pools[this.getActivePoolKey()] || 0;
        },

        getActivePoolScanned() {
            if (! this.scanned[this.activeSettingId]) return 0;
            return (this.scanned[this.activeSettingId][this.getActivePoolKey()] || []).length;
        },

        onPoolChanged() {
            this.errorMessage = '';
            this.scanBuffer = '';
            this.$nextTick(() => {
                if (this.$refs.scanInput) {
                    this.$refs.scanInput.focus();
                }
            });
        },

        totalRequired() {
            let sum = 0;
            Object.values(this.pools).forEach(owner => {
                sum += owner.total;
            });
            return sum;
        },

        totalScanned() {
            let sum = 0;
            Object.values(this.scanned).forEach(settingData => {
                Object.values(settingData).forEach(list => {
                    sum += list.length;
                });
            });
            return sum;
        },

        progressPercentage() {
            const req = this.totalRequired();
            if (req === 0) return 0;
            return Math.round((this.totalScanned() / req) * 100);
        },

        getAllPageScannedSerials() {
            const result = [];
            Object.values(this.scanned).forEach(settingData => {
                Object.values(settingData).forEach(list => {
                    list.forEach(sn => result.push(sn));
                });
            });
            return result;
        },

        formatPoolKeyName(key) {
            const map = {
                'normal_non_tax': 'Normal - Non PPN',
                'normal_tax': 'Normal - PPN',
                'broken_non_tax': 'Rusak - Non PPN',
                'broken_tax': 'Rusak - PPN'
            };
            return map[key] || key;
        },

        async handleScanSubmit() {
            this.errorMessage = '';
            const serial = this.scanBuffer.trim();
            if (! serial) return;

            // Capacity Check
            if (this.getActivePoolScanned() >= this.getActivePoolCapacity()) {
                this.errorMessage = 'Pool ini sudah terisi penuh. Silakan pilih pool yang belum lengkap.';
                return;
            }

            // Client-side Page-wide duplicate check
            const allSerials = this.getAllPageScannedSerials();
            if (allSerials.includes(serial)) {
                this.errorMessage = `Nomor seri "${serial}" sudah di-scan sebelumnya di halaman ini.`;
                return;
            }

            // Server-side AJAX Validation
            try {
                const response = await fetch("{{ route('products.convert-to-serialized.validate-scan') }}", {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-CSRF-TOKEN': '{{ csrf_token() }}',
                        'Accept': 'application/json'
                    },
                    body: JSON.stringify({
                        serial_number: serial,
                        session_serials: allSerials
                    })
                });

                const json = await response.json();

                if (! response.ok || ! json.valid) {
                    this.errorMessage = json.message || 'Nomor seri tidak valid.';
                    return;
                }

                // Add to active pool
                const key = this.getActivePoolKey();
                this.scanned[this.activeSettingId][key].push(json.serial_number);
                this.scanBuffer = '';
                this.$nextTick(() => {
                    if (this.$refs.scanInput) {
                        this.$refs.scanInput.focus();
                    }
                });
            } catch (err) {
                this.errorMessage = 'Terjadi kesalahan jaringan saat memvalidasi nomor seri.';
            }
        },

        removeSerial(settingId, poolKey, serial) {
            if (! this.scanned[settingId] || ! this.scanned[settingId][poolKey]) return;
            this.scanned[settingId][poolKey] = this.scanned[settingId][poolKey].filter(s => s !== serial);
            this.errorMessage = '';
            this.$nextTick(() => {
                if (this.$refs.scanInput) {
                    this.$refs.scanInput.focus();
                }
            });
        },

        async submitConversion() {
            if (this.totalScanned() !== this.totalRequired()) {
                alert('Seluruh pool harus terisi penuh sebelum dapat menyimpan konversi.');
                return;
            }

            if (! confirm('Apakah Anda yakin ingin mengonversi seluruh stok produk ini menjadi ber-serial number? Tindakan ini tidak dapat dibatalkan.')) {
                return;
            }

            this.isSubmitting = true;

            try {
                const response = await fetch("{{ route('products.convert-to-serialized.convert', $product?->id ?? 0) }}", {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-CSRF-TOKEN': '{{ csrf_token() }}',
                        'Accept': 'application/json'
                    },
                    body: JSON.stringify({
                        expected_pools: this.pools,
                        scanned_serials: this.scanned
                    })
                });

                const json = await response.json();

                if (! response.ok || ! json.success) {
                    alert(json.message || 'Terjadi kesalahan saat memproses konversi.');
                    this.isSubmitting = false;
                    return;
                }

                alert(json.message);
                window.location.href = "{{ route('products.show', $product?->id ?? 0) }}";
            } catch (err) {
                alert('Terjadi kesalahan jaringan saat mengirim data konversi.');
                this.isSubmitting = false;
            }
        }
    };
}
</script>
@endsection

@php use Illuminate\Support\Facades\Storage; @endphp
<div>
    <style>
        .settlement-card {
            border: none;
            border-radius: 12px;
            box-shadow: 0 4px 20px rgba(0, 0, 0, 0.05);
            transition: transform 0.2s ease, box-shadow 0.2s ease;
        }
        .settlement-card:hover {
            box-shadow: 0 8px 30px rgba(0, 0, 0, 0.08);
        }
        .stat-card {
            background: #fff;
            border-radius: 12px;
            padding: 1.25rem;
            border: 1px solid rgba(0,0,0,0.05);
            height: 100%;
            display: flex;
            flex-direction: column;
            justify-content: center;
        }
        .stat-icon {
            width: 40px;
            height: 40px;
            border-radius: 10px;
            display: flex;
            align-items: center;
            justify-content: center;
            margin-bottom: 0.75rem;
            font-size: 1.25rem;
        }
        .icon-supplier { background: #e0f2fe; color: #0369a1; }
        .icon-location { background: #fef3c7; color: #92400e; }
        .icon-total { background: #dcfce7; color: #166534; }
        
        .table-premium thead th {
            background-color: #f8fafc;
            border-bottom: 2px solid #e2e8f0;
            color: #64748b;
            font-weight: 600;
            text-transform: uppercase;
            font-size: 0.75rem;
            letter-spacing: 0.025em;
            padding: 1rem 1.5rem;
        }
        .table-premium tbody td {
            padding: 1.25rem 1.5rem;
            vertical-align: middle;
            border-bottom: 1px solid #f1f5f9;
        }
        .table-premium tbody tr:last-child td {
            border-bottom: none;
        }
        .table-premium tbody tr {
            transition: background-color 0.2s ease;
        }
        .table-premium tbody tr:hover {
            background-color: #f8fafc;
        }
        
        .form-select-premium, .form-control-premium {
            border-radius: 8px;
            border: 1px solid #e2e8f0;
            padding: 0.6rem 1rem;
            font-size: 0.9rem;
            transition: border-color 0.2s, box-shadow 0.2s;
        }
        .form-select-premium:focus, .form-control-premium:focus {
            border-color: #3b82f6;
            box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.1);
            outline: none;
        }
        
        .badge-soft-primary { background: #eff6ff; color: #1e40af; border: 1px solid #bfdbfe; }
        .badge-soft-success { background: #f0fdf4; color: #166534; border: 1px solid #bbf7d0; }
        .badge-soft-secondary { background: #f8fafc; color: #475569; border: 1px solid #e2e8f0; }
        .badge-soft-warning { background: #fffbeb; color: #92400e; border: 1px solid #fde68a; }
        .badge-soft-danger { background: #fef2f2; color: #991b1b; border: 1px solid #fecaca; }
        .badge-soft-info { background: #ecfeff; color: #083344; border: 1px solid #a5f3fc; }
        
        .proof-upload-area {
            border: 2px dashed #e2e8f0;
            border-radius: 12px;
            padding: 2rem;
            text-align: center;
            background: #f8fafc;
            cursor: pointer;
            transition: all 0.2s ease;
        }
        .proof-upload-area:hover {
            border-color: #3b82f6;
            background: #eff6ff;
        }
        
        .btn-premium-primary {
            background: #2563eb;
            color: white;
            border-radius: 10px;
            padding: 0.6rem 1.5rem;
            font-weight: 600;
            border: none;
            transition: all 0.2s;
        }
        .btn-premium-primary:hover {
            background: #1d4ed8;
            transform: translateY(-1px);
            box-shadow: 0 4px 12px rgba(37, 99, 235, 0.2);
        }
        .btn-premium-light {
            background: white;
            color: #475569;
            border-radius: 10px;
            padding: 0.6rem 1.5rem;
            font-weight: 600;
            border: 1px solid #e2e8f0;
            transition: all 0.2s;
        }
        .btn-premium-light:hover {
            background: #f8fafc;
            border-color: #cbd5e1;
            transform: translateY(-1px);
        }

        @keyframes fadeIn {
            from { opacity: 0; transform: translateY(10px); }
            to { opacity: 1; transform: translateY(0); }
        }
        .animate-fade-in {
            animation: fadeIn 0.3s ease-out forwards;
        }
    </style>

    <div class="container-fluid py-4">
        <form wire:submit.prevent="submit" class="needs-validation" novalidate>
            @csrf

            <div class="d-flex align-items-center justify-content-between mb-4">
                <div>
                    <h4 class="fw-bold mb-1 text-dark">Penyelesaian Retur</h4>
                    <p class="text-muted mb-0">No. Referensi: <span class="text-primary fw-semibold">{{ $purchaseReturn->reference }}</span></p>
                </div>
                <div class="d-flex gap-2">
                    <span class="badge badge-soft-primary px-3 py-2 text-uppercase">{{ $purchaseReturn->approval_status }}</span>
                    <span class="badge badge-soft-secondary px-3 py-2 text-uppercase">{{ $purchaseReturn->status }}</span>
                </div>
            </div>

            <div class="card mb-4 shadow-sm border-0">
                <div class="card-body">
                    <div class="d-flex align-items-center gap-3">
                        <div class="stat-icon icon-supplier bg-light text-primary rounded-3 p-3">
                            <i class="bi bi-person-badge fs-3"></i>
                        </div>
                        <div>
                            <h6 class="text-muted small text-uppercase fw-bold mb-1">Pemasok</h6>
                            <div class="fw-bold text-dark fs-5">{{ optional($purchaseReturn->supplier)->supplier_name ?? '-' }}</div>
                            <div class="small text-muted">{{ optional($purchaseReturn->supplier)->supplier_email }}</div>
                        </div>
                    </div>
                </div>
            </div>

            @if($isReadOnly)
                <div class="alert alert-soft-success d-flex align-items-center gap-3 p-3 settlement-card bg-white border-start border-4 border-success mb-4" role="alert">
                    <div class="bg-success text-white rounded-circle d-flex align-items-center justify-content-center" style="width: 32px; height: 32px;">
                        <i class="bi bi-check-lg"></i>
                    </div>
                    <div>
                        <h6 class="mb-0 fw-bold text-dark">Penyelesaian Selesai</h6>
                        <span class="text-muted small">Metode penyelesaian sudah ditetapkan sebagai <strong>{{ $displayReturnType }}</strong>.</span>
                    </div>
                </div>
            @endif

            <div class="card settlement-card mb-4">
                <div class="card-header bg-white py-3 border-0">
                    <div class="d-flex align-items-center gap-2">
                        <div class="bg-primary text-white rounded-circle d-flex align-items-center justify-content-center" style="width: 28px; height: 28px; font-size: 0.8rem;">
                            <i class="bi bi-list-check"></i>
                        </div>
                        <h5 class="mb-0 fw-bold">Detail Penyelesaian Per Item</h5>
                    </div>
                </div>
                <div class="table-responsive" style="overflow: visible;">
                    <table class="table table-premium align-middle mb-0">
                        <thead>
                            <tr>
                                <th style="width: 25%">Produk</th>
                                <th style="width: 20%">Nomor Seri / Qty</th>
                                <th style="width: 35%">Metode Penyelesaian</th>
                                @can('purchaseReturns.viewPrice')
                                    <th style="width: 20%" class="text-end">Nilai Penyelesaian</th>
                                @endcan
                            </tr>
                        </thead>
                        <tbody>
                            @php 
                                $processedDetails = []; 
                            @endphp
                            @foreach($settlementLines as $index => $line)
                                <tr>
                                    @if(!in_array($line['detail_id'], $processedDetails))
                                        <td rowspan="{{ $detailCounts[$line['detail_id']] ?? 1 }}" class="bg-white border-end">
                                            <div class="fw-bold text-dark">{{ $line['product_name'] }}</div>
                                            <div class="text-muted small">{{ $line['product_code'] }}</div>
                                        </td>
                                        @php $processedDetails[] = $line['detail_id']; @endphp
                                    @endif
                                    
                                    <td>
                                        <div class="d-flex flex-column gap-2">
                                            @if($line['serial_number'])
                                                <div class="d-inline-flex align-items-center px-2 py-1 rounded bg-light border text-primary small fw-semibold" style="width: fit-content;">
                                                    <i class="bi bi-upc-scan me-1"></i>
                                                    {{ $line['serial_number'] }}
                                                </div>
                                            @else
                                                <div class="d-inline-flex align-items-center text-muted small">
                                                    <i class="bi bi-box-seam me-1"></i>
                                                    Jumlah: <span class="fw-bold ms-1 text-dark">{{ $line['quantity'] ?? 1 }}</span>
                                                </div>
                                            @endif

                                            @php
                                                $statusClass = match($line['status']) {
                                                    'SUBMITTED' => 'badge-soft-warning',
                                                    'APPROVED' => 'badge-soft-success',
                                                    'APPROVED_AWAITING_RECEIVE' => 'badge-soft-warning',
                                                    'RECEIVED' => 'badge-soft-success',
                                                    'REJECTED' => 'badge-soft-danger',
                                                    default => 'badge-soft-secondary',
                                                };
                                                $statusLabel = match($line['status']) {
                                                    'SUBMITTED' => 'Menunggu Persetujuan',
                                                    'APPROVED' => 'Disetujui',
                                                    'APPROVED_AWAITING_RECEIVE' => 'Menunggu Penerimaan',
                                                    'RECEIVED' => 'Diterima',
                                                    'REJECTED' => 'Ditolak',
                                                    default => 'Draft',
                                                };
                                            @endphp
                                            <span class="badge {{ $statusClass }} px-2 py-1" style="width: fit-content; font-size: 0.7rem;">
                                                {{ $statusLabel }}
                                            </span>
                                        </div>
                                    </td>
                                    <td>
                                        @php
                                            $isLineReadOnly = $isReadOnly || in_array($line['status'], ['SUBMITTED', 'APPROVED', 'APPROVED_AWAITING_RECEIVE', 'RECEIVED']);
                                            $isRejected = $line['status'] === 'REJECTED';
                                        @endphp
                                        
                                        @if($isLineReadOnly && !$isRejected)
                                            <div class="p-2 rounded bg-light border border-dashed">
                                                @php
                                                    $methodLabel = $allMethods[$line['method']] ?? ($line['method'] ?: 'Belum ditentukan');
                                                @endphp
                                                <div class="fw-bold text-dark small">{{ $methodLabel }}</div>
                                                @if(in_array($line['method'], ['MODIFY_PURCHASE', 'CREDIT', 'CASH']) && $line['target_purchase_id'])
                                                    @php
                                                        $targetPurchase = null;
                                                        if ($line['method'] === 'MODIFY_PURCHASE' || $line['method'] === 'CASH') {
                                                            $methodKey = $line['method'];
                                                            foreach ($unpaidPurchases as $prodPurchases) {
                                                                if (isset($prodPurchases[$methodKey])) {
                                                                    $found = collect($prodPurchases[$methodKey])->firstWhere('id', $line['target_purchase_id']);
                                                                    if ($found) { $targetPurchase = $found; break; }
                                                                }
                                                            }
                                                        } else {
                                                            $targetPurchase = collect($creditPurchases)->firstWhere('id', $line['target_purchase_id']);
                                                        }
                                                    @endphp
                                                    <div class="small text-muted mt-1">
                                                        <i class="bi bi-file-earmark-text me-1"></i>
                                                        {{ $targetPurchase ? ($targetPurchase['text'] ?? $targetPurchase['label']) : 'Nota #'. $line['target_purchase_id'] }}
                                                    </div>
                                                @endif
                                            </div>
                                        @else
                                            <div class="d-flex flex-column gap-2" style="position: relative;">
                                                @if($isRejected)
                                                    <div class="alert alert-danger py-2 px-3 mb-2 small rounded-3 border-0 d-flex align-items-center justify-content-between">
                                                        <div>
                                                            <i class="bi bi-exclamation-octagon me-2"></i>
                                                            <strong>Ditolak:</strong> {{ $line['rejection_reason'] ?: 'Tidak ada alasan' }}
                                                        </div>
                                                        <button type="button" wire:click="resetLine({{ $index }})" class="btn btn-sm btn-outline-danger py-0 px-2" style="font-size: 0.7rem;">
                                                            Reset
                                                        </button>
                                                    </div>
                                                @endif

                                                <div class="d-flex gap-2">
                                                    <div class="flex-grow-1">
                                                        <select class="form-select form-select-premium form-select-sm @error('settlementLines.'.$index.'.method') is-invalid @enderror" 
                                                            wire:model.live="settlementLines.{{ $index }}.method"
                                                            data-index="{{ $index }}"
                                                            data-status="{{ $line['status'] }}"
                                                            data-max-nominal="{{ $line['max_nominal'] }}">
                                                            <option value="">-- Pilih Metode --</option>
                                                            @foreach($this->getMethodsForLine($index) as $value => $label)
                                                                <option value="{{ $value }}">{{ $label }}</option>
                                                            @endforeach
                                                        </select>
                                                        @error('settlementLines.'.$index.'.method')
                                                            <div class="invalid-feedback d-block">{{ $message }}</div>
                                                        @enderror
                                                    </div>
                                                    
                                                    @if(!empty($line['method']) && ($line['status'] === 'DRAFT' || $line['status'] === 'REJECTED'))
                                                        <button type="button" 
                                                            wire:click="submitLine({{ $index }})" 
                                                            wire:loading.attr="disabled"
                                                            class="btn btn-sm btn-outline-primary px-3 d-flex align-items-center"
                                                            title="Kirim untuk persetujuan">
                                                            <span wire:loading.remove wire:target="submitLine({{ $index }})">
                                                                <i class="bi bi-send-fill"></i>
                                                            </span>
                                                            <span wire:loading wire:target="submitLine({{ $index }})" class="spinner-border spinner-border-sm" role="status"></span>
                                                        </button>
                                                    @endif
                                                </div>
                                                
                                                <!-- Searchable Dropdown Integration via Alpine -->
                                                @php
                                                    $currentMethod = $settlementLines[$index]['method'] ?? '';
                                                    $showDropdown = in_array($currentMethod, ['MODIFY_PURCHASE', 'CREDIT', 'CASH']);
                                                    $purchaseList = match($currentMethod) {
                                                        'MODIFY_PURCHASE' => $unpaidPurchases[$line['product_id']]['MODIFY_PURCHASE'] ?? [],
                                                        'CASH' => $unpaidPurchases[$line['product_id']]['CASH'] ?? [],
                                                        'CREDIT' => $creditPurchases,
                                                        default => []
                                                    };
                                                    $placeholder = match($currentMethod) {
                                                        'MODIFY_PURCHASE' => 'Cari Nota...',
                                                        'CASH' => 'Cari Nota (Lunas/Sebagian)...',
                                                        'CREDIT' => 'Cari Nota (Referensi)...',
                                                        default => 'Cari...'
                                                    };
                                                    $originId = $line['origin_purchase_id'] ?? null;
                                                    $originPaymentStatus = strtoupper($line['origin_purchase_payment_status'] ?? '');
                                                    $originPaid = (float) ($line['origin_purchase_paid_amount'] ?? 0);
                                                    $originDue = (float) ($line['origin_purchase_due_amount'] ?? 0);
                                                    $originLabel = $line['origin_purchase_label'] ?? '';
                                                    $returnValue = (float) ($line['nominal'] ?? ($line['max_nominal'] ?? 0));
                                                    $isOriginUnpaid = $originPaid <= 0 || $originPaymentStatus === 'UNPAID';
                                                    $isFixedSource = $currentMethod === 'MODIFY_PURCHASE'
                                                        && empty($line['serial_number_id'])
                                                        && $originId
                                                        && $isOriginUnpaid
                                                        && $returnValue <= $originDue;
                                                    $isLocked = !empty($line['serial_number_id']) && in_array($currentMethod, ['MODIFY_PURCHASE', 'CASH']);
                                                    $excludedId = $currentMethod === 'CREDIT' ? ($originId ?? null) : null;
                                                @endphp

                                                @if($showDropdown && $isFixedSource)
                                                    <div>
                                                        <label class="small fw-bold text-muted mb-1">Nota Pembelian Sumber :</label>
                                                        <div class="form-control form-control-premium form-control-sm d-flex justify-content-between align-items-center bg-light cursor-not-allowed">
                                                            <span>{{ $originLabel ?: ('Nota #' . $originId) }}</span>
                                                            <i class="bi bi-lock-fill small text-muted"></i>
                                                        </div>
                                                    </div>
                                                @elseif($showDropdown)
                                                    <div class="" 
                                                         wire:key="dropdown-{{ $index }}-{{ $currentMethod }}"
                                                         x-data="{
                                                            open: false,
                                                            search: '',
                                                            selectedId: @entangle('settlementLines.'.$index.'.target_purchase_id'),
                                                            options: {{ json_encode($purchaseList) }},
                                                            locked: {{ $isLocked ? 'true' : 'false' }},
                                                            excludedId: {{ $excludedId ?? 'null' }},
                                                            dropdownStyles: {},
                                                            init() {
                                                                this.handleScroll = this.handleScroll.bind(this);
                                                                this.onClickOutside = this.onClickOutside.bind(this);
                                                                this.handleResize = this.close.bind(this);

                                                                this.$watch('open', value => {
                                                                    if (value) {
                                                                        this.updatePosition();
                                                                        window.addEventListener('scroll', this.handleScroll, true);
                                                                        window.addEventListener('resize', this.handleResize);
                                                                        // Add click listener with delay to avoid immediate trigger
                                                                        setTimeout(() => {
                                                                            window.addEventListener('click', this.onClickOutside);
                                                                        }, 50);
                                                                    } else {
                                                                        window.removeEventListener('scroll', this.handleScroll, true);
                                                                        window.removeEventListener('resize', this.handleResize);
                                                                        window.removeEventListener('click', this.onClickOutside);
                                                                    }
                                                                });
                                                            },
                                                            handleScroll() {
                                                                this.updatePosition();
                                                            },
                                                            onClickOutside(e) {
                                                                if (this.open && !this.$refs.trigger.contains(e.target) && !this.$refs.dropdown.contains(e.target)) {
                                                                    this.open = false;
                                                                }
                                                            },
                                                            close() {
                                                                this.open = false;
                                                            },
                                                            updatePosition() {
                                                                if (!this.open) return;
                                                                this.$nextTick(() => {
                                                                    if (!this.$refs.trigger) return;
                                                                    const rect = this.$refs.trigger.getBoundingClientRect();
                                                                    this.dropdownStyles = {
                                                                        position: 'fixed',
                                                                        top: `${rect.bottom}px`,
                                                                        left: `${rect.left}px`,
                                                                        width: `${rect.width}px`,
                                                                        zIndex: 9999,
                                                                        maxHeight: '200px',
                                                                        overflowY: 'auto'
                                                                    };
                                                                });
                                                            },
                                                            get filteredOptions() {
                                                                let filtered = this.options;
                                                                if (this.excludedId) {
                                                                    filtered = filtered.filter(o => o.id != this.excludedId);
                                                                }
                                                                if (this.search === '') return filtered;
                                                                return filtered.filter(option => 
                                                                    option.text.toLowerCase().includes(this.search.toLowerCase())
                                                                );
                                                            },
                                                            get selectedLabel() {
                                                                let opt = this.options.find(o => o.id == this.selectedId);
                                                                return opt ? opt.text : '';
                                                            },
                                                            select(id) {
                                                                if (this.locked) return;
                                                                this.selectedId = id;
                                                                this.open = false;
                                                                this.search = '';
                                                            }
                                                         }"
                                                    >
                                                        <label class="small fw-bold text-muted mb-1">{{ ($currentMethod == 'MODIFY_PURCHASE') ? 'Nota Pembelian Sumber :' : 'Referensi Nota :' }}</label>
                                                        <div class="position-relative">
                                                            <div x-ref="trigger" 
                                                                 @click="if(!locked) open = !open" 
                                                                 class="form-control form-control-premium form-control-sm d-flex justify-content-between align-items-center" 
                                                                 :class="{
                                                                    'is-invalid': @error('settlementLines.'.$index.'.target_purchase_id') true @else false @enderror,
                                                                    'bg-light cursor-not-allowed': locked,
                                                                    'cursor-pointer': !locked
                                                                 }">
                                                                <span x-text="selectedLabel || '{{ $placeholder }}'" :class="{'text-muted': !selectedLabel}"></span>
                                                                <i x-show="!locked" class="bi bi-chevron-down small text-muted"></i>
                                                                <i x-show="locked" class="bi bi-lock-fill small text-muted"></i>
                                                            </div>
                                                            
                                                            <div x-show="open" 
                                                                 x-ref="dropdown"
                                                                 class="bg-white border rounded shadow-sm mt-1"
                                                                 :style="dropdownStyles"
                                                                 x-transition>
                                                                <div class="p-2 sticky-top bg-white border-bottom">
                                                                    <input type="text" x-model="search" class="form-control form-control-sm" placeholder="Ketik untuk mencari...">
                                                                </div>
                                                                <div class="list-group list-group-flush">
                                                                    <template x-for="option in filteredOptions" :key="option.id">
                                                                        <button type="button" 
                                                                            class="list-group-item list-group-item-action small py-2" 
                                                                            @click="select(option.id)"
                                                                            :class="{'active': selectedId == option.id}"
                                                                        >
                                                                            <div class="fw-bold" x-text="option.text"></div>
                                                                            <div class="small text-muted" x-text="option.label"></div>
                                                                        </button>
                                                                    </template>
                                                                    <div x-show="filteredOptions.length === 0" class="p-2 text-muted small text-center">
                                                                        Tidak ditemukan.
                                                                    </div>
                                                                </div>
                                                            </div>
                                                        </div>
                                                        @error('settlementLines.'.$index.'.target_purchase_id')
                                                            <div class="invalid-feedback d-block">{{ $message }}</div>
                                                        @enderror

                                                        {{-- Ticket 3: Quantity Mismatch Warning --}}
                                                        @if($showDropdown && in_array($currentMethod, ['MODIFY_PURCHASE', 'CASH']) && empty($line['serial_number']))
                                                            @php
                                                                $selectedPurchaseData = collect($purchaseList)->firstWhere('id', $line['target_purchase_id']);
                                                                $purchaseQty = $selectedPurchaseData['product_quantity'] ?? 0;
                                                                $returnQty = $line['quantity'] ?? 0;
                                                                $showWarning = $line['target_purchase_id'] && $purchaseQty > 0 && $returnQty > $purchaseQty;
                                                            @endphp
                                                            @if($showWarning)
                                                                <div class="alert alert-warning py-2 px-3 mt-2 small rounded-3 border-0 d-flex align-items-center animate-fade-in">
                                                                    <i class="bi bi-exclamation-triangle-fill me-2"></i>
                                                                    <span>Jumlah retur ({{ $returnQty }}) melebihi jumlah pembelian ({{ $purchaseQty }})</span>
                                                                </div>
                                                            @endif
                                                        @endif
                                                    </div>
                                                @endif
                                            </div>
                                        @endif
                                    </td>
                                    @if($canViewPrice)
                                        <td class="text-end">
                                            @php
                                                $currentMethod = $settlementLines[$index]['method'] ?? '';
                                                $showNominal = in_array($currentMethod, ['CREDIT', 'CASH', 'MODIFY_PURCHASE']); // Include Modify Purchase
                                                $isLineReadOnly = $isReadOnly || in_array($line['status'], ['SUBMITTED', 'APPROVED']);
                                            @endphp
                                            
                                            @if($isLineReadOnly)
                                                @if($showNominal)
                                                    <div class="fw-bold text-dark fs-5">{{ format_currency($line['nominal']) }}</div>
                                                    <div class="small text-muted">Batas: {{ format_currency($line['max_nominal']) }}</div>
                                                @else
                                                    <span class="text-muted small">-</span>
                                                @endif
                                            @else
                                                @if($showNominal)
                                                    <div class="mb-1 ms-auto" style="max-width: 200px;">
                                                        <input 
                                                            x-data="{ 
                                                                nominal: @entangle('settlementLines.'.$index.'.nominal'),
                                                                format(val) { 
                                                                    if (val === null || val === '' || isNaN(parseFloat(val))) return '';
                                                                    let num = parseFloat(val);
                                                                    return 'Rp ' + new Intl.NumberFormat('id-ID', { 
                                                                        minimumFractionDigits: 0, 
                                                                        maximumFractionDigits: 0 
                                                                    }).format(num);
                                                                },
                                                                parse(val) { 
                                                                    if (typeof val !== 'string') return val;
                                                                    let clean = val.replace(/[^0-9]/g, '');
                                                                    return clean === '' ? 0 : parseFloat(clean);
                                                                }
                                                            }"
                                                            type="text" 
                                                            name="{{ 'settlementLines.'.$index.'.nominal' }}"
                                                            wire:key="nominal-input-{{ $index }}"
                                                            class="form-control form-control-premium text-end settlement-nominal @error('settlementLines.'.$index.'.nominal') is-invalid @enderror" 
                                                            x-bind:value="format(nominal)"
                                                            x-on:focus="$el.value = (nominal || 0); $el.select()"
                                                            x-on:blur="$el.value = format(nominal)"
                                                            x-on:input="nominal = parse($el.value)"
                                                            placeholder="0"
                                                        >
                                                    </div>
                                                    <div class="small text-muted">Maks: <span class="fw-semibold text-dark">{{ format_currency($line['max_nominal']) }}</span></div>
                                                    @error('settlementLines.'.$index.'.nominal')
                                                        <div class="invalid-feedback d-block text-start">{{ $message }}</div>
                                                    @enderror
                                                @else
                                                    <div class="text-muted small">-</div>
                                                @endif
                                            @endif
                                        </td>
                                    @endif
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </div>


            <div class="d-flex justify-content-end gap-3 pb-5">
                <a href="{{ route('purchase-returns.show', $purchaseReturn->id) }}" class="btn btn-premium-light">
                    <i class="bi bi-arrow-left me-1"></i> Kembali
                </a>
                @unless($isReadOnly)
                    <button type="submit" class="btn btn-premium-primary" wire:loading.attr="disabled">
                        <span wire:loading.remove>
                            <i class="bi bi-save me-1"></i> Simpan Draft
                        </span>
                        <span wire:loading class="spinner-border spinner-border-sm" role="status" aria-hidden="true"></span>
                    </button>
                @endunless
            </div>
        </form>
    </div>
</div>
<script>
    document.addEventListener('change', function (e) {
        var target = e.target;
        if (!target) return;
        if (target.matches('select[data-index][data-max-nominal]')) {
            var methodVal = target.value;
            var index = target.getAttribute('data-index');
            var status = target.getAttribute('data-status');
            var maxNom = target.getAttribute('data-max-nominal');
            if (!index) return;
            if (!['DRAFT', 'REJECTED'].includes(status)) return;
            // For purchase-return, CASH constant is 'CASH'
            if (methodVal !== '{{ \Modules\PurchasesReturn\Entities\PurchaseReturnDetail::METHOD_CASH }}') {
                var inputSelector = 'input[name="settlementLines.' + index + '.nominal"]';
                var inputEl = document.querySelector(inputSelector);
                if (!inputEl) return;
                var num = parseFloat(maxNom) || 0;
                inputEl.value = 'Rp ' + new Intl.NumberFormat('id-ID', { minimumFractionDigits: 0, maximumFractionDigits: 0 }).format(num);
                inputEl.dispatchEvent(new Event('input', { bubbles: true }));
                inputEl.dispatchEvent(new Event('change', { bubbles: true }));
            }
        }
    });
</script>


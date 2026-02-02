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
        .icon-customer { background: #e0f2fe; color: #0369a1; }
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
                    <h4 class="fw-bold mb-1 text-dark">Penyelesaian Retur Penjualan</h4>
                    <p class="text-muted mb-0">No. Referensi: <span class="text-primary fw-semibold">{{ $saleReturn->reference }}</span></p>
                </div>
                <div class="d-flex gap-2">
                    <span class="badge badge-soft-primary px-3 py-2 text-uppercase">{{ $saleReturn->status }}</span>
                </div>
            </div>

            <div class="row mb-4">
                <div class="col-md-6">
                    <div class="card h-100 shadow-sm border-0">
                        <div class="card-body">
                            <div class="d-flex align-items-center gap-3">
                                <div class="stat-icon icon-customer bg-light text-primary rounded-3 p-3">
                                    <i class="bi bi-person-fill fs-3"></i>
                                </div>
                                <div>
                                    <h6 class="text-muted small text-uppercase fw-bold mb-1">Pelanggan</h6>
                                    <div class="fw-bold text-dark fs-5">{{ $saleReturn->customer_name ?? optional($saleReturn->customer)->customer_name ?? '-' }}</div>
                                    <div class="small text-muted">{{ optional($saleReturn->customer)->customer_email }}</div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="col-md-6">
                    <div class="card h-100 shadow-sm border-0">
                        <div class="card-body">
                            <div class="d-flex align-items-center gap-3">
                                <div class="stat-icon icon-total bg-light text-success rounded-3 p-3">
                                    <i class="bi bi-currency-dollar fs-3"></i>
                                </div>
                                <div>
                                    <h6 class="text-muted small text-uppercase fw-bold mb-1">Total Nilai Retur</h6>
                                    <div class="fw-bold text-dark fs-5">{{ format_currency($saleReturn->total_amount) }}</div>
                                </div>
                            </div>
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
                        <span class="text-muted small">Semua item telah diselesaikan.</span>
                    </div>
                </div>
            @endif

            <div class="card settlement-card mb-4">
                <div class="card-header bg-white py-3 border-0">
                    <div class="d-flex align-items-center gap-2">
                        <div class="bg-primary text-white rounded-circle d-flex align-items-center justify-content-center" style="width: 28px; height: 28px; font-size: 0.8rem;">
                            <i class="bi bi-list-check"></i>
                        </div>
                        <h5 class="mb-0 fw-bold">Detail Toko & Penyelesaian Per Item</h5>
                    </div>
                </div>
                <div class="table-responsive" style="overflow: visible;">
                    <table class="table table-premium align-middle mb-0">
                        <thead>
                            <tr>
                                <th style="width: 25%">Produk</th>
                                <th style="width: 15%">Seri / Qty</th>
                                <th style="width: 25%">Metode</th>
                                <th style="width: 20%">Nilai</th>
                                <th style="width: 15%" class="text-center">Aksi</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach($settlementLines as $index => $line)
                                @php
                                    $statusClass = match($line['status']) {
                                        'SUBMITTED' => 'badge-soft-warning',
                                        'APPROVED' => 'badge-soft-success',
                                        'REJECTED' => 'badge-soft-danger',
                                        default => 'badge-soft-secondary',
                                    };
                                    $statusLabel = match($line['status']) {
                                        'SUBMITTED' => 'Menunggu',
                                        'APPROVED' => 'Disetujui',
                                        'REJECTED' => 'Ditolak',
                                        default => 'Draft',
                                    };
                                    $isSubmittable = $line['status'] === 'DRAFT' || $line['status'] === 'REJECTED';
                                @endphp
                                <tr>
                                    <td>
                                        <div class="fw-bold text-dark small">{{ $line['product_name'] }}</div>
                                        <div class="text-muted" style="font-size: 0.75rem;">{{ $line['product_code'] }}</div>
                                    </td>
                                    <td>
                                        @if($line['serial_number'])
                                            <span class="badge badge-soft-info px-2 py-1 small">
                                                <i class="bi bi-upc-scan me-1"></i>{{ $line['serial_number'] }}
                                            </span>
                                        @else
                                            <span class="text-muted small">Qty: {{ $line['quantity'] ?? 1 }}</span>
                                        @endif
                                        <div class="mt-1">
                                            <span class="badge {{ $statusClass }}" style="font-size: 0.65rem;">{{ $statusLabel }}</span>
                                        </div>
                                    </td>
                                    <td>
                                        @if($line['status'] === 'DRAFT' || $line['status'] === 'REJECTED')
                                            <select class="form-select form-select-premium form-select-sm" wire:model.live="settlementLines.{{ $index }}.method">
                                                <option value="">-- Pilih --</option>
                                                @foreach($methods as $val => $lab)
                                                    <option value="{{ $val }}">{{ $lab }}</option>
                                                @endforeach
                                            </select>

                                            {{-- Dynamic Inputs based on Method --}}
                                            @if($line['method'] === \Modules\SalesReturn\Entities\SaleReturnDetail::METHOD_PRODUCT_REPAIR)
                                                <div class="mt-2">
                                                    @if($line['serial_number_id'])
                                                         {{-- Serial Replacement --}}
                                                        <input type="text" class="form-control form-control-premium form-control-sm" 
                                                               wire:model.blur="settlementLines.{{ $index }}.new_serial_number" 
                                                               placeholder="Serial Baru">
                                                    @else
                                                        {{-- Non-Serial Replacement --}}
                                                        <select class="form-select form-select-premium form-select-sm" 
                                                                wire:model.live="settlementLines.{{ $index }}.location_id">
                                                            <option value="">-- Pilih Lokasi --</option>
                                                            @foreach($locations as $loc)
                                                                <option value="{{ $loc->id }}">{{ $loc->name }}</option>
                                                            @endforeach
                                                        </select>
                                                    @endif
                                                </div>
                                            @endif

                                            @if($line['method'] === \Modules\SalesReturn\Entities\SaleReturnDetail::METHOD_UNPROCESSED)
                                                <div class="mt-2">
                                                     <textarea class="form-control form-control-premium form-control-sm" 
                                                               wire:model.blur="settlementLines.{{ $index }}.notes" 
                                                               placeholder="Alasan..." rows="1"></textarea>
                                                </div>
                                            @endif
                                        @else
                                            <div class="p-2 rounded bg-light border small">
                                                {{ $allMethods[$line['method']] ?? '-' }}
                                            </div>
                                        @endif
                                    </td>
                                    <td>
                                        @if($line['status'] === 'DRAFT' || $line['status'] === 'REJECTED')
                                            @if($line['method'] === \Modules\SalesReturn\Entities\SaleReturnDetail::METHOD_CASH_REFUND)
                                                <div class="input-group input-group-sm">
                                                    <span class="input-group-text">Rp</span>
                                                    <input type="number" class="form-control form-control-premium text-end" 
                                                           wire:model.blur="settlementLines.{{ $index }}.nominal"
                                                           max="{{ $line['max_nominal'] }}">
                                                </div>
                                                {{-- Proof Upload Minimalist --}}
                                                <div class="mt-1">
                                                     <input type="file" class="form-control form-control-sm" style="font-size: 0.7rem;"
                                                           wire:model="settlementLines.{{ $index }}.proof_file">
                                                </div>
                                            @else
                                               <div class="text-end fw-bold">{{ format_currency($line['nominal']) }}</div>
                                            @endif
                                        @else
                                            <div class="text-end fw-bold {{ $line['method'] === \Modules\SalesReturn\Entities\SaleReturnDetail::METHOD_CASH_REFUND ? 'text-success' : '' }}">
                                                {{ format_currency($line['nominal']) }}
                                            </div>
                                        @endif
                                        
                                        {{-- Read Only Details --}}
                                        @if(!($line['status'] === 'DRAFT' || $line['status'] === 'REJECTED'))
                                            <div class="small mt-1 text-end">
                                                @if($line['method'] === \Modules\SalesReturn\Entities\SaleReturnDetail::METHOD_PRODUCT_REPAIR)
                                                    @if(!empty($line['new_serial_number']))
                                                        <span class="text-muted">SN Baru:</span> <strong>{{ $line['new_serial_number'] }}</strong>
                                                    @elseif(!empty($line['location_id']))
                                                        @php $locName = $locations->firstWhere('id', $line['location_id'])?->name ?? '-'; @endphp
                                                        <span class="text-muted">Lokasi:</span> <strong>{{ $locName }}</strong>
                                                    @endif
                                                @elseif($line['method'] === \Modules\SalesReturn\Entities\SaleReturnDetail::METHOD_CASH_REFUND)
                                                    @if(!empty($line['proof_path'] ?? null))
                                                        @php 
                                                            $proof = \Modules\SalesReturn\Entities\SaleReturnItemSettlement::find($line['id'])?->proof_path;
                                                        @endphp
                                                        @if($proof)
                                                            <a href="{{ Storage::url($proof) }}" target="_blank" class="badge badge-soft-info text-decoration-none">
                                                                <i class="bi bi-paperclip"></i> Bukti
                                                            </a>
                                                        @endif
                                                    @endif
                                                @elseif($line['method'] === \Modules\SalesReturn\Entities\SaleReturnDetail::METHOD_UNPROCESSED)
                                                    <div class="text-muted fst-italic">"{{ $line['notes'] }}"</div>
                                                @endif
                                            </div>
                                        @endif
                                    </td>
                                    <td class="text-center">
                                        <div class="d-flex justify-content-center gap-1">
                                            @if($isSubmittable)
                                                <button type="button" wire:click="submitLine({{ $index }})" class="btn btn-sm btn-primary" title="Kirim">
                                                    <i class="bi bi-send"></i>
                                                </button>
                                                @if($line['status'] === 'REJECTED')
                                                    <button type="button" wire:click="resetLine({{ $index }})" class="btn btn-sm btn-outline-secondary" title="Reset">
                                                        <i class="bi bi-arrow-counterclockwise"></i>
                                                    </button>
                                                @endif
                                            @endif
                                        </div>
                                    </td>
                                </tr>
                                @if($line['status'] === 'REJECTED' && $line['rejection_reason'])
                                    <tr class="table-danger">
                                        <td colspan="5" class="py-1 px-3 small">
                                            <i class="bi bi-exclamation-triangle me-2"></i><strong>Alasan Penolakan:</strong> {{ $line['rejection_reason'] }}
                                        </td>
                                    </tr>
                                @endif
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </div>

            <div class="d-flex justify-content-end gap-3 pb-5">
                <a href="{{ route('sale-returns.show', $saleReturn->id) }}" class="btn btn-premium-light">
                    <i class="bi bi-arrow-left me-1"></i> Kembali
                </a>
            </div>
        </form>
    </div>
</div>

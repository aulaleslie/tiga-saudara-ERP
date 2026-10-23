<div class="row mb-4">
    <div class="col-sm-4">
        <h6 class="mb-3">Informasi Transaksi:</h6>
        <div>Struk: <strong>{{ $snapshot['header']['receipt_number'] }}</strong></div>
        <div>Tanggal: {{ \Carbon\Carbon::parse($snapshot['header']['date'])->format('d M Y H:i') }}</div>
        <div>Pelanggan: {{ $snapshot['header']['customer_name'] ?? 'Guest' }}</div>
    </div>
    <div class="col-sm-4">
        <h6 class="mb-3">{{ $paymentHeading }}</h6>
        @foreach($snapshot['payments'] as $payment)
            <div>{{ $payment['method_name'] }}: {{ format_currency($payment['amount']) }}</div>
        @endforeach
        <div class="mt-2">Total: <strong>{{ format_currency($snapshot['header']['grand_total']) }}</strong></div>
    </div>
    <div class="col-sm-4">
        <h6 class="mb-3">Informasi Tambahan:</h6>
        <div class="alert alert-info py-2 mb-0">
            <small><i class="fas fa-info-circle mr-1"></i> Pilih resolusi untuk setiap item: Tidak Ada Aksi, Uang Kembali, atau Ganti Produk.</small>
        </div>
    </div>
</div>

@error('lineSelections')
    <div class="alert alert-danger">{{ $message }}</div>
@enderror

@foreach($this->groupedLines as $groupIndex => $group)
    <div class="card mb-3 border" wire:key="group-{{ $group['pos_transaction_line_id'] ?? $group['sale_detail_id'] }}">
        <div class="card-header py-2 bg-light d-flex justify-content-between align-items-center">
            <div>
                <strong>{{ $group['product_name'] }}</strong>
                <small class="text-muted ml-2">{{ $group['product_code'] }}</small>
                @if($group['is_bundle'])
                    <span class="badge badge-info ml-2">Bundle</span>
                @endif
                @if($group['is_tracked'])
                    <span class="badge badge-primary ml-2">Tracked Serial</span>
                @endif
            </div>
            <div class="text-muted small">
                Harga Satuan: <strong>{{ format_currency($group['unit_price']) }}</strong>
            </div>
        </div>
        <div class="card-body p-0">
            @if(!empty($group['serial_lines']))
                <table class="table table-sm mb-0">
                    <thead class="thead-light">
                        <tr>
                            <th style="width: 200px;">Serial Number</th>
                            <th class="text-center" style="width: 100px;">Status</th>
                            <th class="text-center">Resolusi</th>
                            <th class="text-center" style="width: 250px;">Detail</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($group['serial_lines'] as $serialLine)
                            @php
                                $lineKey = \Modules\Pos\Livewire\PosReturn\PosReturnCreateForm::buildLineKey($serialLine);
                                $selection = $lineSelections[$lineKey] ?? ['resolution' => 'none'];
                                $serialNumber = $serialLine['serial_numbers'][0]['serial_number'] ?? '?';
                                $resolution = $selection['resolution'] ?? 'none';
                                $ownLineQty = $this->getExistingSerialLineQuantity($serialLine['sale_detail_id'], $serialLine['serial_number_ids'][0] ?? null);
                                $effectiveReturnable = $serialLine['returnable_quantity'] + $ownLineQty;
                                $isReturnable = $effectiveReturnable > 0;
                            @endphp
                            <tr wire:key="serial-row-{{ $lineKey }}">
                                <td>
                                    <code class="font-weight-bold">{{ $serialNumber }}</code>
                                </td>
                                <td class="text-center">
                                    @if($isReturnable)
                                        <span class="badge badge-success">Tersedia</span>
                                    @else
                                        <span class="badge badge-secondary">Sudah Diretur</span>
                                    @endif
                                </td>
                                <td class="text-center">
                                    @if($isReturnable)
                                        <div class="btn-group btn-group-sm" role="group">
                                            <button type="button"
                                                    wire:click="updateResolution('{{ $lineKey }}', 'none')"
                                                    class="btn {{ $resolution === 'none' ? 'btn-secondary' : 'btn-outline-secondary' }}">
                                                <i class="fas fa-ban"></i> Tidak
                                            </button>
                                            <button type="button"
                                                    wire:click="updateResolution('{{ $lineKey }}', 'cash_return')"
                                                    class="btn {{ $resolution === 'cash_return' ? 'btn-success' : 'btn-outline-success' }}">
                                                <i class="fas fa-money-bill-wave"></i> Tunai
                                            </button>
                                            <button type="button"
                                                    wire:click="updateResolution('{{ $lineKey }}', 'product_replacement')"
                                                    class="btn {{ $resolution === 'product_replacement' ? 'btn-info' : 'btn-outline-info' }}">
                                                <i class="fas fa-exchange-alt"></i> Ganti
                                            </button>
                                        </div>
                                    @else
                                        <span class="text-muted">-</span>
                                    @endif
                                </td>
                                <td class="text-center">
                                    @if($resolution === 'cash_return')
                                        @php
                                            $bundleScope = $selection['bundle_return_scope'] ?? \Modules\Pos\Entities\PosReturnLine::BUNDLE_RETURN_SCOPE_WHOLE_BUNDLE;
                                            $componentRetainedPerUnit = $group['is_bundle'] ? (float) collect($group['bundle_items'] ?? [])->sum(fn($bi) => (float) ($bi['allocated_price'] ?? 0)) : 0;
                                            $parentAllocatedPrice = (float) ($serialLine['parent_allocated_price'] ?? ($serialLine['unit_price'] - $componentRetainedPerUnit));
                                            if ($parentAllocatedPrice <= 0 && $serialLine['unit_price'] > 0) {
                                                $parentAllocatedPrice = (float) $serialLine['unit_price'];
                                            }
                                            $lineRefund = ($group['is_bundle'] && $bundleScope === \Modules\Pos\Entities\PosReturnLine::BUNDLE_RETURN_SCOPE_PARENT_ONLY)
                                                ? $parentAllocatedPrice
                                                : $serialLine['unit_price'];
                                            $retainedAmount = ($group['is_bundle'] && $bundleScope === \Modules\Pos\Entities\PosReturnLine::BUNDLE_RETURN_SCOPE_PARENT_ONLY)
                                                ? $componentRetainedPerUnit
                                                : 0;
                                        @endphp
                                        <div class="text-success font-weight-bold">
                                            <i class="fas fa-money-bill-wave mr-1"></i>
                                            {{ format_currency($lineRefund) }}
                                        </div>
                                        @if($group['is_bundle'])
                                            <div class="btn-group btn-group-sm mt-1" role="group">
                                                <button type="button"
                                                        wire:click="updateBundleScope('{{ $lineKey }}', 'whole_bundle')"
                                                        class="btn {{ $bundleScope === 'whole_bundle' ? 'btn-primary' : 'btn-outline-primary' }} btn-xs">
                                                    Paket Lengkap
                                                </button>
                                                <button type="button"
                                                        wire:click="updateBundleScope('{{ $lineKey }}', 'parent_only')"
                                                        class="btn {{ $bundleScope === 'parent_only' ? 'btn-primary' : 'btn-outline-primary' }} btn-xs">
                                                    Hanya Utama
                                                </button>
                                            </div>
                                            @if($retainedAmount > 0)
                                                <div class="text-danger small mt-1 font-weight-bold">
                                                    Ditahan: {{ format_currency($retainedAmount) }}
                                                </div>
                                            @endif
                                        @endif
                                    @endif

                                    @if($resolution === 'product_replacement')
                                        @if(!empty($selection['replacement_serial_id']))
                                            <div class="d-flex align-items-center justify-content-center">
                                                <span class="badge badge-info p-2 mr-1">
                                                    <i class="fas fa-exchange-alt mr-1"></i>
                                                    {{ $selection['replacement_serial_label'] ?? '?' }}
                                                </span>
                                                <button type="button" class="btn btn-sm btn-outline-danger" wire:click="clearReplacementSerial('{{ $lineKey }}')" title="Hapus serial pengganti">
                                                    <i class="fas fa-times"></i>
                                                </button>
                                            </div>
                                        @else
                                            <div class="input-group input-group-sm">
                                                <input type="text"
                                                       wire:model.defer="lineSelections.{{ $lineKey }}.replacement_serial_input"
                                                       wire:keydown.enter.prevent="scanReplacementSerial('{{ $lineKey }}')"
                                                       class="form-control text-center @error('lineSelections.'.$lineKey.'.replacement_serial_input') is-invalid @enderror"
                                                       placeholder="Scan SN pengganti...">
                                                <div class="input-group-append">
                                                    <button type="button" class="btn btn-info" wire:click="scanReplacementSerial('{{ $lineKey }}')">
                                                        <i class="fas fa-barcode"></i>
                                                    </button>
                                                </div>
                                            </div>
                                            @error("lineSelections.{$lineKey}.replacement_serial_input")
                                                <div class="text-danger small mt-1">{{ $message }}</div>
                                            @enderror
                                        @endif
                                        <input type="text"
                                               wire:model.defer="lineSelections.{{ $lineKey }}.replacement_reason"
                                               class="form-control form-control-sm mt-1 @error('lineSelections.'.$lineKey.'.replacement_reason') is-invalid @enderror"
                                               placeholder="Alasan penggantian (opsional)">
                                        @error("lineSelections.{$lineKey}.replacement_reason")
                                            <div class="text-danger small mt-1">{{ $message }}</div>
                                        @enderror
                                    @endif

                                    @if($resolution === 'none')
                                        <span class="text-muted small">Tidak ada aksi</span>
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>

                @if($group['is_bundle'] && !empty($group['bundle_items']))
                    @include('livewire.modules.pos.pos-return.partials.bundle-component-controls', ['components' => $group['bundle_items']])
                @endif

            @elseif($group['non_serial_line'])
                @php
                    $nsLine = $group['non_serial_line'];
                    $nsKey = \Modules\Pos\Livewire\PosReturn\PosReturnCreateForm::buildLineKey($nsLine);
                    $nsSelection = $lineSelections[$nsKey] ?? ['resolution' => 'none', 'quantity' => 0];
                    $nsResolution = $nsSelection['resolution'] ?? 'none';
                    $nsQuantity = (float) ($nsSelection['quantity'] ?? 0);
                    $ownNsQty = $this->getExistingNonSerialLineQuantity($nsLine['sale_detail_id']);
                    $nsAvailable = $nsLine['returnable_quantity'] + $ownNsQty;
                    $nsIsReturnable = $nsAvailable > 0;
                @endphp
                <div class="p-3">
                    <div class="row align-items-center">
                        <div class="col-sm-3">
                            <div>
                                Tersedia: <strong>{{ $nsAvailable }}</strong>
                                @if($nsLine['returned_quantity'] - $ownNsQty > 0)
                                    <span class="small text-danger ml-2">Retur Lain: {{ $nsLine['returned_quantity'] - $ownNsQty }}</span>
                                @elseif($nsLine['returned_quantity'] > 0)
                                    <span class="small text-danger ml-2">Ter-retur: {{ $nsLine['returned_quantity'] }}</span>
                                @endif
                            </div>
                        </div>
                        <div class="col-sm-3 text-center">
                            @if($nsIsReturnable)
                                <div class="btn-group btn-group-sm" role="group">
                                    <button type="button"
                                            wire:click="updateResolution('{{ $nsKey }}', 'none')"
                                            class="btn {{ $nsResolution === 'none' ? 'btn-secondary' : 'btn-outline-secondary' }}">
                                        Tidak
                                    </button>
                                    <button type="button"
                                            wire:click="updateResolution('{{ $nsKey }}', 'cash_return')"
                                            class="btn {{ $nsResolution === 'cash_return' ? 'btn-success' : 'btn-outline-success' }}">
                                        Tunai
                                    </button>
                                    <button type="button"
                                            wire:click="updateResolution('{{ $nsKey }}', 'product_replacement')"
                                            class="btn {{ $nsResolution === 'product_replacement' ? 'btn-info' : 'btn-outline-info' }}">
                                        Ganti
                                    </button>
                                </div>
                            @else
                                <span class="badge badge-secondary">Habis</span>
                            @endif
                        </div>
                        <div class="col-sm-3 text-center">
                            @if($nsIsReturnable && $nsResolution !== 'none')
                                <input type="number"
                                       wire:model.live="lineSelections.{{ $nsKey }}.quantity"
                                       class="form-control form-control-sm text-center font-weight-bold"
                                       min="0"
                                       max="{{ $nsAvailable }}"
                                       step="1"
                                       placeholder="Jumlah">
                            @endif
                        </div>
                        <div class="col-sm-3 text-right">
                            @if($nsResolution === 'cash_return' && $nsQuantity > 0)
                                @php
                                    $bundleScope = $nsSelection['bundle_return_scope'] ?? \Modules\Pos\Entities\PosReturnLine::BUNDLE_RETURN_SCOPE_WHOLE_BUNDLE;
                                    $componentRetainedPerUnit = $group['is_bundle'] ? (float) collect($group['bundle_items'] ?? [])->sum(fn($bi) => (float) ($bi['allocated_price'] ?? 0)) : 0;
                                    $parentAllocatedPrice = (float) ($nsLine['parent_allocated_price'] ?? ($nsLine['unit_price'] - $componentRetainedPerUnit));
                                    if ($parentAllocatedPrice <= 0 && $nsLine['unit_price'] > 0) {
                                        $parentAllocatedPrice = (float) $nsLine['unit_price'];
                                    }
                                    $lineRefund = ($group['is_bundle'] && $bundleScope === \Modules\Pos\Entities\PosReturnLine::BUNDLE_RETURN_SCOPE_PARENT_ONLY)
                                        ? $nsQuantity * $parentAllocatedPrice
                                        : $nsQuantity * $nsLine['unit_price'];
                                @endphp
                                <div class="text-success font-weight-bold">
                                    <i class="fas fa-money-bill-wave mr-1"></i>
                                    {{ format_currency($lineRefund) }}
                                </div>
                            @endif
                        </div>
                    </div>

                    @if($group['is_bundle'] && $nsResolution === 'cash_return')
                        @php
                            $bundleScope = $nsSelection['bundle_return_scope'] ?? \Modules\Pos\Entities\PosReturnLine::BUNDLE_RETURN_SCOPE_WHOLE_BUNDLE;
                            $componentRetainedPerUnit = (float) collect($group['bundle_items'] ?? [])->sum(fn($bi) => (float) ($bi['allocated_price'] ?? 0));
                            $parentAllocatedPrice = (float) ($nsLine['parent_allocated_price'] ?? ($nsLine['unit_price'] - $componentRetainedPerUnit));
                            if ($parentAllocatedPrice <= 0 && $nsLine['unit_price'] > 0) {
                                $parentAllocatedPrice = (float) $nsLine['unit_price'];
                                $componentRetainedPerUnit = 0;
                            }
                            $retainedAmount = ($bundleScope === \Modules\Pos\Entities\PosReturnLine::BUNDLE_RETURN_SCOPE_PARENT_ONLY)
                                ? $nsQuantity * $componentRetainedPerUnit
                                : 0;
                        @endphp
                        <div class="mt-3 pt-2 border-top">
                            <div class="d-flex align-items-center justify-content-between">
                                <div>
                                    <span class="small font-weight-bold text-muted mr-2">Lingkup Retur Bundel:</span>
                                    <div class="btn-group btn-group-sm" role="group">
                                        <button type="button"
                                                wire:click="updateBundleScope('{{ $nsKey }}', 'whole_bundle')"
                                                class="btn {{ $bundleScope === 'whole_bundle' ? 'btn-primary' : 'btn-outline-primary' }}">
                                            <i class="fas fa-boxes mr-1"></i> Paket Lengkap
                                        </button>
                                        <button type="button"
                                                wire:click="updateBundleScope('{{ $nsKey }}', 'parent_only')"
                                                class="btn {{ $bundleScope === 'parent_only' ? 'btn-primary' : 'btn-outline-primary' }}">
                                            <i class="fas fa-box-open mr-1"></i> Hanya Produk Utama
                                        </button>
                                    </div>
                                </div>
                                <div class="text-right">
                                    @if($bundleScope === 'parent_only')
                                        <span class="badge badge-warning">Hanya Produk Utama</span>
                                        @if($retainedAmount > 0)
                                            <div class="text-danger small font-weight-bold mt-1">
                                                Tagihan komponen ditahan: {{ format_currency($retainedAmount) }}
                                            </div>
                                        @endif
                                    @else
                                        <span class="badge badge-info">Paket Lengkap</span>
                                        <div class="text-muted small mt-1">
                                            Termasuk seluruh komponen
                                        </div>
                                    @endif
                                </div>
                            </div>
                        </div>
                    @endif

                    @if($nsResolution === 'product_replacement')
                        <div class="row mt-2">
                            <div class="col-sm-12">
                                <input type="text"
                                       wire:model.defer="lineSelections.{{ $nsKey }}.replacement_reason"
                                       class="form-control form-control-sm @error('lineSelections.'.$nsKey.'.replacement_reason') is-invalid @enderror"
                                       placeholder="Alasan penggantian">
                                @error("lineSelections.{$nsKey}.replacement_reason")
                                    <div class="text-danger small mt-1">{{ $message }}</div>
                                @enderror
                            </div>
                        </div>
                    @endif
                </div>

                @if($group['is_bundle'] && !empty($group['bundle_items']))
                    @include('livewire.modules.pos.pos-return.partials.bundle-component-controls', ['components' => $group['bundle_items']])
                @endif
            @endif
        </div>
    </div>
@endforeach

@php
    $totalCashReturn = 0;
    $totalActionable = 0;
    $totalRetainedCharges = 0;
    foreach ($snapshot['lines'] as $line) {
        $key = \Modules\Pos\Livewire\PosReturn\PosReturnCreateForm::buildLineKey($line);
        $sel = $lineSelections[$key] ?? ['resolution' => 'none'];
        $res = $sel['resolution'] ?? 'none';
        if ($res === 'cash_return') {
            $scope = $sel['bundle_return_scope'] ?? \Modules\Pos\Entities\PosReturnLine::BUNDLE_RETURN_SCOPE_WHOLE_BUNDLE;
            $compRetainedPerUnit = !empty($line['is_bundle']) ? (float) collect($line['bundle_items'] ?? [])->sum(fn($bi) => (float) ($bi['allocated_price'] ?? 0)) : 0;
            $parentAllocPrice = (float) ($line['parent_allocated_price'] ?? ($line['unit_price'] - $compRetainedPerUnit));
            if ($parentAllocPrice <= 0 && $line['unit_price'] > 0) {
                $parentAllocPrice = (float) $line['unit_price'];
                $compRetainedPerUnit = 0;
            }

            if ($line['is_tracked']) {
                $lineRefund = (!empty($line['is_bundle']) && $scope === \Modules\Pos\Entities\PosReturnLine::BUNDLE_RETURN_SCOPE_PARENT_ONLY)
                    ? $parentAllocPrice
                    : $line['unit_price'];
                $totalCashReturn += $lineRefund;
                if (!empty($line['is_bundle']) && $scope === \Modules\Pos\Entities\PosReturnLine::BUNDLE_RETURN_SCOPE_PARENT_ONLY) {
                    $totalRetainedCharges += $compRetainedPerUnit;
                }
                $totalActionable++;
            } else {
                $qty = (float) ($sel['quantity'] ?? 0);
                $lineRefund = (!empty($line['is_bundle']) && $scope === \Modules\Pos\Entities\PosReturnLine::BUNDLE_RETURN_SCOPE_PARENT_ONLY)
                    ? $qty * $parentAllocPrice
                    : $qty * $line['unit_price'];
                $totalCashReturn += $lineRefund;
                if (!empty($line['is_bundle']) && $scope === \Modules\Pos\Entities\PosReturnLine::BUNDLE_RETURN_SCOPE_PARENT_ONLY) {
                    $totalRetainedCharges += $qty * $compRetainedPerUnit;
                }
                if ($qty > 0) {
                    $totalActionable++;
                }
            }
        } elseif ($res === 'product_replacement') {
            $totalActionable++;
        }
    }
@endphp
<div class="card bg-light">
    <div class="card-body py-3">
        <div class="row align-items-center">
            <div class="col-sm-4">
                <div class="text-muted">Item aktif: <strong>{{ $totalActionable }}</strong></div>
            </div>
            <div class="col-sm-4 text-center">
                @if($totalRetainedCharges > 0)
                    <div class="text-danger">
                        Tagihan Ditahan: <strong>{{ format_currency($totalRetainedCharges) }}</strong>
                    </div>
                @endif
            </div>
            <div class="col-sm-4 text-right">
                <div>Total Retur Tunai: <strong class="text-success">{{ format_currency($totalCashReturn) }}</strong></div>
            </div>
        </div>
    </div>
</div>

@if($error)
    <div class="alert alert-danger mt-3">
        {{ $error }}
    </div>
@endif
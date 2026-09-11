<div>
    @include('utils.alerts')

    <form wire:submit.prevent="saveDraft">
        @csrf

        <div class="row mt-3">
            <div class="col-md-6">
                <label class="form-label" id="stock-condition-label">Kondisi Stok</label>
                @if(isset($transfer) && $transfer->exists)
                    {{-- Condition is immutable once a transfer is persisted: it
                         determines stock/serial eligibility for every row, so it
                         is rendered as read-only context here rather than a
                         writable control. A historical mixed-condition record
                         has no single persisted condition to show and never
                         reaches ordinary editing (blocked server-side), but the
                         badge still degrades to a neutral label defensively. --}}
                    <div>
                        @if($isMixedConditionHistory)
                            <span class="badge bg-secondary">Campuran (Riwayat)</span>
                        @elseif($stockCondition === \Modules\Adjustment\Entities\Transfer::CONDITION_BREAKAGE)
                            <span class="badge bg-warning text-dark">Barang Rusak</span>
                        @else
                            <span class="badge bg-success">Barang Baik</span>
                        @endif
                    </div>
                @else
                    @php
                        $isGoodSelected = $stockCondition === \Modules\Adjustment\Entities\Transfer::CONDITION_GOOD;
                        $isBreakageSelected = $stockCondition === \Modules\Adjustment\Entities\Transfer::CONDITION_BREAKAGE;
                    @endphp
                    {{-- Plain Bootstrap 4/CoreUI 3 buttons driven entirely by
                         Livewire click handlers: no wire:model / btn-check
                         (a Bootstrap 5 component) and no data-toggle="buttons"
                         JS, so component state is always the single source
                         of truth for which condition is highlighted. --}}
                    <div class="btn-group segmented-control" role="group" aria-labelledby="stock-condition-label">
                        <button type="button"
                                id="stock-condition-good"
                                wire:click="selectStockCondition('{{ \Modules\Adjustment\Entities\Transfer::CONDITION_GOOD }}')"
                                wire:loading.attr="disabled"
                                wire:target="selectStockCondition,saveDraft,submitForApproval"
                                class="btn {{ $isGoodSelected ? 'btn-success active' : 'btn-outline-success' }}"
                                aria-pressed="{{ $isGoodSelected ? 'true' : 'false' }}">
                            <i class="bi bi-check-circle"></i> Barang Baik
                            @if($isGoodSelected)
                                <span class="sr-only">(dipilih)</span>
                            @endif
                        </button>

                        <button type="button"
                                id="stock-condition-breakage"
                                wire:click="selectStockCondition('{{ \Modules\Adjustment\Entities\Transfer::CONDITION_BREAKAGE }}')"
                                wire:loading.attr="disabled"
                                wire:target="selectStockCondition,saveDraft,submitForApproval"
                                class="btn {{ $isBreakageSelected ? 'btn-warning active' : 'btn-outline-warning' }}"
                                aria-pressed="{{ $isBreakageSelected ? 'true' : 'false' }}">
                            <i class="bi bi-exclamation-triangle"></i> Barang Rusak
                            @if($isBreakageSelected)
                                <span class="sr-only">(dipilih)</span>
                            @endif
                        </button>
                    </div>
                @endif
                @if(!empty($selfManagedValidationErrors['stock_condition']))
                    <div class="text-danger mt-1" role="alert">
                        {{ $selfManagedValidationErrors['stock_condition'] }}
                    </div>
                @endif
                @if($isMixedConditionHistory)
                    <div class="alert alert-warning mt-2">
                        Transfer ini berisi campuran barang baik dan rusak dari data lama. Pilih satu kondisi stok untuk melanjutkan; baris yang tidak sesuai akan dihapus.
                    </div>
                @endif
            </div>
        </div>

        @if($showConditionConfirmModal)
            <div class="modal fade show d-block" style="background: rgba(0,0,0,0.5); z-index: 1060;" role="dialog" aria-modal="true">
                <div class="modal-dialog">
                    <div class="modal-content">
                        <div class="modal-header bg-warning">
                            <h5 class="modal-title">Konfirmasi Perubahan Kondisi Stok</h5>
                        </div>
                        <div class="modal-body">
                            <p>Mengubah kondisi stok akan menghapus seluruh baris produk yang telah dimasukkan. Lanjutkan?</p>
                        </div>
                        <div class="modal-footer">
                            <button type="button" wire:click="cancelConditionChange" class="btn btn-secondary">Batal</button>
                            <button type="button" wire:click="confirmConditionChange" class="btn btn-warning">Ya, Ganti &amp; Hapus Baris</button>
                        </div>
                    </div>
                </div>
            </div>
        @endif

        <div class="row mt-3">
            <div class="col-md-6">
                <label class="form-label">Lokasi Asal</label>
                @if(isset($transfer) && $transfer->exists)
                    {{-- Origin is immutable once a transfer exists: document numbering
                         is sequenced per origin and stock/serial validation is scoped
                         to the persisted origin, so it is shown read-only here. --}}
                    <input type="text" class="form-control" value="{{ $transfer->originLocation?->name }}" disabled readonly>
                @else
                    @livewire('modules.setting.location-search-dropdown', [
                        'name' => 'origin_location',
                        'selected' => $originLocation,
                        'placeholder' => 'Pilih Lokasi Asal...',
                        'dispatchTo' => 'transfer.transfer-stock-form',
                        'selectedSettingId' => $currentSetting->id,
                        'tenantScoped' => true,
                        'crossBusiness' => false,
                    ], key('origin-location-dropdown'))
                @endif
                @if(!empty($selfManagedValidationErrors['origin_location']))
                    <span class="text-danger">
                        {{ $selfManagedValidationErrors['origin_location'] }}
                    </span>
                @endif
            </div>

            <div class="col-md-6">
                <label class="form-label">Lokasi Tujuan</label>
                @livewire('modules.setting.location-search-dropdown', [
                    'name' => 'destination_location',
                    'selected' => $destinationLocation,
                    'placeholder' => 'Pilih Lokasi Tujuan...',
                    'dispatchTo' => 'transfer.transfer-stock-form',
                    'excludedLocationIds' => $originLocation ? [(int) $originLocation] : [],
                    'crossBusiness' => true,
                ], key('destination-location-dropdown-' . ($originLocation ?? 'none')))
                @if(!empty($selfManagedValidationErrors['destination_location']))
                    <span class="text-danger">
                        {{ $selfManagedValidationErrors['destination_location'] }}
                    </span>
                @endif
            </div>
        </div>

        <div class="mt-4">
            @if(!$originLocation)
                <div class="alert alert-info">
                    Silakan pilih Lokasi Asal terlebih dahulu untuk mulai memasukkan produk.
                </div>
            @endif
            <livewire:transfer.search-product
                :locationId="$originLocation"
                :stockCondition="$stockCondition"
                wire:key="search-product-{{ $originLocation ?? 'none' }}"
            />

            @if(!empty($selfManagedValidationErrors['rows']))
                <div class="alert alert-danger">
                    {{ $selfManagedValidationErrors['rows'] }}
                </div>
            @endif
            <livewire:transfer.transfer-product-table
                :originLocationId="$originLocation"
                :destinationLocationId="$destinationLocation"
                :existingProducts="$rows"
                wire:model.defer="products"
                wire:key="transfer-table-{{ $originLocation ?? 'none' }}-{{ $stockCondition ?? 'none' }}"
            />
        </div>

        <div class="text-right mt-4">
            @if(isset($transfer) && $transfer->exists)
                @can('stockTransfers.edit')
                <button type="button" wire:click="saveDraft" class="btn btn-secondary">
                    Simpan Draf <i class="bi bi-save"></i>
                </button>
                @if($transfer->status === \Modules\Adjustment\Entities\Transfer::STATUS_DRAFT)
                <button type="button" wire:click="submitForApproval" class="btn btn-primary">
                    Ajukan Persetujuan <i class="bi bi-check"></i>
                </button>
                @endif
                @endcan
            @else
                @can('stockTransfers.create')
                <button type="button" wire:click="saveDraft" class="btn btn-success">
                    Simpan Draf <i class="bi bi-save"></i>
                </button>
                @endcan
            @endif
        </div>
    </form>
</div>

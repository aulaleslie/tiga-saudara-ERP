<div>
    @include('utils.alerts')

    <form wire:submit.prevent="saveDraft">
        @csrf

        <div class="row mt-3">
            <div class="col-md-6">
                <label class="form-label">Kondisi Stok</label>
                <select wire:model.live="stockCondition" class="form-select">
                    <option value="">-- Pilih Kondisi Stok --</option>
                    <option value="{{ \Modules\Adjustment\Entities\Transfer::CONDITION_GOOD }}">Barang Baik</option>
                    <option value="{{ \Modules\Adjustment\Entities\Transfer::CONDITION_BREAKAGE }}">Barang Rusak</option>
                </select>
                @if(!empty($selfManagedValidationErrors['stock_condition']))
                    <span class="text-danger">
                        {{ $selfManagedValidationErrors['stock_condition'] }}
                    </span>
                @endif
                @if($isMixedConditionHistory)
                    <div class="alert alert-warning mt-2">
                        Transfer ini berisi campuran barang baik dan rusak dari data lama. Pilih satu kondisi stok untuk melanjutkan; baris yang tidak sesuai akan dihapus.
                    </div>
                @endif
            </div>
        </div>

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

<div>
    @include('utils.alerts')

    <form wire:submit.prevent="submit">
        @csrf

        <div class="row mt-3">
            <div class="col-md-6">
                <livewire:auto-complete.location-business-loader
                    :locationId="$originLocation"
                    :settingId="$currentSetting->id"
                    name="origin_location"
                    label="Lokasi Asal"
                    eventName="originLocationSelected"
                />
                @if(!empty($selfManagedValidationErrors['origin_location']))
                    <span class="text-danger">
                        {{ $selfManagedValidationErrors['origin_location'] }}
                    </span>
                @endif
            </div>

            <div class="col-md-6">
                <livewire:auto-complete.location-business-loader
                    :locationId="$destinationLocation"
                    name="destination_location"
                    label="Lokasi Tujuan"
                    eventName="destinationLocationSelected"
                    :exclude="$originLocation"
                    :key="'destination-'.$originLocation"
                />
                @if(!empty($selfManagedValidationErrors['destination_location']))
                    <span class="text-danger">
                        {{ $selfManagedValidationErrors['destination_location'] }}
                    </span>
                @endif
            </div>
        </div>

        <div class="mt-4">
            <livewire:transfer.search-product
                :locationId="$originLocation"
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
                wire:key="transfer-table-{{ $originLocation ?? 'none' }}"
            />
        </div>

        <div class="text-right mt-4">
            @if(isset($transfer) && $transfer->exists)
                @can('stockTransfers.edit')
                <button type="button" wire:click="submit('update')" class="btn btn-secondary">
                    Update Draf <i class="bi bi-save"></i>
                </button>
                @if($transfer->status === \Modules\Adjustment\Entities\Transfer::STATUS_DRAFT || $transfer->status === \Modules\Adjustment\Entities\Transfer::STATUS_REJECTED)
                <button type="button" wire:click="submit('resubmit')" class="btn btn-primary">
                    Ajukan Ulang (Resubmit) <i class="bi bi-check"></i>
                </button>
                @endif
                @endcan
            @else
                @can('stockTransfers.create')
                <button type="button" wire:click="submit('create')" class="btn btn-success">
                    Simpan <i class="bi bi-check"></i>
                </button>
                @endcan
            @endif
        </div>
    </form>
</div>

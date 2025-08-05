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
                wire:model.defer="products"
                wire:key="transfer-table-{{ $originLocation ?? 'none' }}"
            />
        </div>

        @can('stockTransfers.create')
            <div class="text-right mt-4">
                <button
                    type="submit"
                    class="btn btn-success"
                    wire:loading.attr="disabled"
                    wire:target="submit"
                >
                    Simpan <i class="bi bi-check"></i>
                </button>
            </div>
        @endcan
    </form>
</div>

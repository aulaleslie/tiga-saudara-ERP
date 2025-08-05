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
            </div>
        </div>

        <div class="mt-4">
            <livewire:transfer.search-product
                :locationId="$originLocation"
                wire:key="search-product-{{ $originLocation ?? 'none' }}"
            />
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
                    {{-- disable & show spinner while submit() is running --}}
                    wire:loading.attr="disabled"
                    wire:target="submit"
                >
                    Simpan <i class="bi bi-check"></i>
                </button>
            </div>
        @endcan

    </form>
</div>

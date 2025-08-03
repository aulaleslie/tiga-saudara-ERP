<div>
    @include('utils.alerts')

    <form action="{{ route('transfers.store') }}" method="POST">
        @csrf

        {{-- 1) Business selector stays the same --}}
        {{-- 2) Origin loader --}}
        <div class="row mt-3">
            <div class="col-md-6">
                <livewire:auto-complete.location-business-loader
                    :locationId="$originLocation"
                    :settingId="$currentSetting->id"
                    name="origin_location"
                    label="Origin Location"
                    eventName="originLocationSelected"
                />
            </div>

            <div class="col-md-6">
                <livewire:auto-complete.location-business-loader
                    :locationId="$destinationLocation"
                    name="destination_location"
                    label="Destination Location"
                    eventName="destinationLocationSelected"
                    :exclude="$originLocation"
                    :key="'destination-'.$originLocation"
                />
            </div>
        </div>

        {{-- 3) Once confirmed, show your two existing children --}}
        <div class="mt-4">
            <livewire:transfer.search-product
                :locationId="$originLocation"
                wire:key="search-product-{{ $originLocation ?? 'none' }}"
            />
            <livewire:transfer.transfer-product-table
                :originLocationId="$originLocation"
                :destinationLocationId="$destinationLocation"
                wire:key="transfer-table-{{ $originLocation ?? 'none' }}"
            />
        </div>

        @can('stockTransfers.create')
            <div class="text-right mt-4">
                <button type="submit" class="btn btn-success">
                    Simpan <i class="bi bi-check"></i>
                </button>
            </div>
        @endcan
    </form>
</div>

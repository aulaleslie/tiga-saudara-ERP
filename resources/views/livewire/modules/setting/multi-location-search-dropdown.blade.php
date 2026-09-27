@php($options = $this->filteredOptions)
@php($selectedLocations = $this->selectedLocations)

<div class="multi-location-search-dropdown">
    <!-- Selected Locations Badges/List -->
    @if(count($selectedLocations) > 0)
        <div class="selected-locations-container mb-2 d-flex flex-wrap gap-2">
            @foreach($selectedLocations as $loc)
                <span class="badge bg-primary text-white p-2 d-inline-flex align-items-center me-1 mb-1" style="font-size: 0.875rem;">
                    <i class="bi bi-geo-alt me-1"></i>
                    <span>{{ $loc['name'] }}</span>
                    <button type="button"
                            class="btn-close btn-close-white ms-2"
                            style="font-size: 0.65rem;"
                            wire:click.stop="removeLocation({{ $loc['id'] }})"
                            title="Hapus lokasi"
                            aria-label="Hapus">
                    </button>
                </span>
            @endforeach
        </div>
    @endif

    <!-- Dropdown Selector Button & Menu -->
    <div class="position-relative" wire:click.away="closeDropdown">
        <button type="button"
                class="form-control d-flex justify-content-between align-items-center text-start @if($error) is-invalid @endif"
                wire:click="toggleDropdown">
            <span class="text-muted text-truncate me-2">
                <i class="bi bi-plus-circle me-1"></i> {{ $placeholder }}
            </span>
            <i class="bi {{ $open ? 'bi-chevron-up' : 'bi-chevron-down' }} flex-shrink-0"></i>
        </button>

        @if($open)
            <div class="dropdown-menu w-100 shadow show p-2"
                 style="position: absolute; z-index: {{ $zIndex }}; max-height: 300px; overflow-y: auto; top: 100%; left: 0; right: 0;">
                <input
                    type="text"
                    class="form-control form-control-sm mb-2"
                    wire:model.live.debounce.300ms="search"
                    wire:keydown.enter.prevent
                    placeholder="Cari lokasi gudang / toko..."
                    autocomplete="off"
                    autofocus
                >

                @if(count($options))
                    @foreach($options as $option)
                        <button
                            type="button"
                            class="dropdown-item text-truncate d-flex align-items-center justify-content-between"
                            wire:click="select('{{ $option['id'] }}')"
                            wire:key="multi-location-option-{{ $option['id'] }}"
                            title="{{ $option['name'] }}"
                        >
                            <span>{{ $option['name'] }}</span>
                            @if($option['is_pkp'])
                                <span class="badge bg-info text-dark ms-2" style="font-size: 0.7rem;">PKP</span>
                            @else
                                <span class="badge bg-secondary text-white ms-2" style="font-size: 0.7rem;">Non-PKP</span>
                            @endif
                        </button>
                    @endforeach
                @else
                    <div class="dropdown-item disabled text-muted">
                        @if(empty($search))
                            Semua lokasi aktif sudah dipilih
                        @else
                            Tidak ada hasil untuk "{{ $search }}"
                        @endif
                    </div>
                @endif
            </div>
        @endif

        <!-- Hidden inputs for traditional form POST -->
        @foreach($selected as $selId)
            <input type="hidden" name="{{ $formName ?? $name }}[]" value="{{ $selId }}">
        @endforeach

        @if($error)
            <div class="text-danger small mt-1">{{ $error }}</div>
        @endif
    </div>
</div>

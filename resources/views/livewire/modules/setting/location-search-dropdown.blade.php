@php($options = $this->filteredOptions)

<div class="d-flex">
    <div class="flex-grow-1 position-relative" wire:click.away="closeDropdown">
        <button type="button"
                class="form-control d-flex justify-content-between align-items-center text-start"
                wire:click="toggleDropdown">
            <span class="{{ $selectedLabel ? '' : 'text-muted' }} text-truncate me-2" title="{{ $selectedLabel ?? $placeholder }}">
                {{ $selectedLabel ?? $placeholder }}
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
                    placeholder="Cari lokasi..."
                    autocomplete="off"
                >

                @if(count($options))
                    @foreach($options as $option)
                        <button
                            type="button"
                            class="dropdown-item text-truncate"
                            wire:click="select('{{ $option['id'] }}')"
                            wire:key="location-option-{{ $option['id'] }}"
                            title="{{ $option['name'] }}"
                        >
                            {{ $option['name'] }}
                        </button>
                    @endforeach
                @else
                    <div class="dropdown-item disabled">Tidak ada hasil</div>
                @endif
            </div>
        @endif

        <input type="hidden" name="{{ $formName ?? $name }}" value="{{ $selected ?? '' }}">

        @if($error)
            <div class="text-danger small mt-1">{{ $error }}</div>
        @endif
    </div>

    @if($allowCreate)
        <button type="button"
                class="btn btn-outline-primary btn-sm ms-1"
                onclick="Livewire.dispatch('openLocationModal')"
                data-toggle="tooltip"
                title="Tambah lokasi baru">
            <i class="bi bi-plus-circle"></i>
        </button>
    @endif
</div>

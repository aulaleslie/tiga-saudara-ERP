@php($options = $this->filteredOptions)

<div class="d-flex position-relative"
     style="{{ $width ? 'min-width: ' . $width . ';' : '' }}"
     data-unit-dropdown="{{ $name }}"
>
    <div class="flex-grow-1 position-relative" wire:click.away="closeDropdown">
        <button type="button"
                class="form-control d-flex justify-content-between align-items-center text-start {{ $disabled ? 'bg-light text-muted' : '' }}"
                wire:click="toggleDropdown"
                style="{{ $width ? 'min-width: ' . $width . ';' : '' }}"
                @disabled($disabled)
                data-role="unit-dropdown-button"
        >
            <span class="{{ $selectedLabel ? '' : 'text-muted' }}"
                  data-role="unit-dropdown-label"
                  data-placeholder="{{ $placeholder }}">
                {{ $selectedLabel ?? $placeholder }}
            </span>
            <i class="bi {{ $open ? 'bi-chevron-up' : 'bi-chevron-down' }}"></i>
        </button>

        @if($open)
            <div class="dropdown-menu w-100 shadow show p-2"
                 style="position: absolute; z-index: 5000; max-height: 300px; overflow-y: auto; top: 100%; left: 0; right: 0; {{ $width ? 'min-width: ' . $width . ';' : '' }}">
                <input
                    type="text"
                    class="form-control form-control-sm mb-2"
                    wire:model.live.debounce.300ms="search"
                    placeholder="Cari unit..."
                    autocomplete="off"
                >

                @if(count($options))
                    @foreach($options as $option)
                        <button
                            type="button"
                            class="dropdown-item"
                            wire:click="select('{{ $option['id'] }}')"
                            wire:key="unit-option-{{ $option['id'] }}"
                        >
                            {{ $option['name'] }}
                        </button>
                    @endforeach
                @else
                    <div class="dropdown-item disabled">Tidak ada hasil</div>
                @endif
            </div>
        @endif

        <input type="hidden" name="{{ $name }}" value="{{ $selected ?? '' }}" @disabled($disabled)>

        @if($error)
            <div class="text-danger small mt-1">{{ $error }}</div>
        @endif
    </div>

    @if($allowCreate)
        <button type="button"
                class="btn btn-outline-primary btn-sm ms-1"
                wire:click="openCreateModal"
                data-toggle="tooltip"
                title="Tambah unit baru">
            <i class="bi bi-plus-circle"></i>
        </button>
    @endif
</div>

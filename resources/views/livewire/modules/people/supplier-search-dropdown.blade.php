@php($options = $this->filteredOptions)

<div class="d-flex">
    <div class="flex-grow-1 position-relative"
         x-data="{ open: @entangle('open').live }"
         @click.away="if (open) open = false">
        <button type="button"
                class="form-control d-flex justify-content-between align-items-center text-start"
                @click="open = !open">
            <span class="{{ $selectedLabel ? '' : 'text-muted' }} text-truncate me-2" title="{{ $selectedLabel ?? $placeholder }}">
                {{ $selectedLabel ?? $placeholder }}
            </span>
            <i class="bi flex-shrink-0" :class="open ? 'bi-chevron-up' : 'bi-chevron-down'"></i>
        </button>

        <div class="dropdown-menu w-100 shadow show p-2"
             x-cloak
             x-show="open"
             style="position: absolute; z-index: 1050; max-height: 300px; overflow-y: auto; top: 100%; left: 0; right: 0;">
            <input
                type="text"
                class="form-control form-control-sm mb-2"
                wire:model.live.debounce.300ms="search"
                placeholder="Cari pemasok..."
                autocomplete="off"
            >

            @if(count($options))
                @foreach($options as $option)
                    <button
                        type="button"
                        class="dropdown-item text-truncate"
                        wire:click="select('{{ $option['id'] }}')"
                        @click="open = false"
                        wire:key="supplier-option-{{ $option['id'] }}"
                        title="{{ $option['name'] }}"
                    >
                        {{ $option['name'] }}
                    </button>
                @endforeach
            @else
                <div class="dropdown-item disabled">Tidak ada hasil</div>
            @endif
        </div>

        <input type="hidden" name="{{ $name }}" value="{{ $selected ?? '' }}">

        @if($error)
            <div class="text-danger small mt-1">{{ $error }}</div>
        @endif
    </div>

    @if($allowCreate)
        <button type="button"
                class="btn btn-outline-primary btn-sm ms-1"
                onclick="Livewire.dispatch('openSupplierModal')"
                data-bs-toggle="tooltip"
                title="Tambah pemasok baru">
            <i class="bi bi-plus-circle"></i>
        </button>
    @endif
</div>

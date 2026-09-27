@php($options = $this->filteredOptions)

<div class="d-flex" x-data="{ isOpen: @entangle('isOpen').live }">
    <div class="flex-grow-1 position-relative"
         @click.away="if (isOpen) isOpen = false">
        <button type="button"
                class="form-control d-flex justify-content-between align-items-center text-start"
                @click="isOpen = !isOpen">
            <span class="{{ $selectedLabel ? '' : 'text-muted' }}">
                {{ $selectedLabel ?? $placeholder }}
            </span>
            <i class="bi" :class="isOpen ? 'bi-chevron-up' : 'bi-chevron-down'"></i>
        </button>

        <div class="dropdown-menu w-100 shadow p-2 show"
             x-cloak
             x-show="isOpen"
             style="position: absolute; z-index: 1050; max-height: 300px; overflow-y: auto; top: 100%; left: 0; right: 0;">
            <input
                type="text"
                class="form-control form-control-sm mb-2"
                wire:model.live.debounce.300ms="search"
                placeholder="Cari term pembayaran..."
                autocomplete="off"
            >

            @if(count($options))
                @foreach($options as $option)
                    <button
                        type="button"
                        class="dropdown-item"
                        wire:click="select('{{ $option['id'] }}')"
                        @click="isOpen = false"
                        wire:key="payment-term-option-{{ $option['id'] }}"
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
                onclick="Livewire.dispatch('openPaymentTermModal')"
                data-toggle="tooltip"
                title="Tambah term pembayaran baru">
            <i class="bi bi-plus-circle"></i>
        </button>
    @endif
</div>

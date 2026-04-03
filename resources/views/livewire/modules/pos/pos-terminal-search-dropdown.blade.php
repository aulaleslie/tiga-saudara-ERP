@php($options = $this->filteredOptions)

<div class="d-flex" x-data="{ isOpen: @entangle('isOpen').live }">
    <div class="flex-grow-1 position-relative"
         @click.away="if (isOpen) isOpen = false">
        <button type="button"
                class="form-control d-flex justify-content-between align-items-center text-start"
                @click="if (!@js($disabled)) { isOpen = !isOpen }"
                @disabled($disabled)
                @if($disabledReason) title="{{ $disabledReason }}" @endif>
            <span class="{{ $selectedLabel ? '' : 'text-muted' }} text-truncate me-2" title="{{ $selectedLabel ?? $placeholder }}">
                {{ $selectedLabel ?? $placeholder }}
            </span>
            <i class="bi flex-shrink-0" :class="isOpen ? 'bi-chevron-up' : 'bi-chevron-down'"></i>
        </button>

        @unless($disabled)
            <div class="dropdown-menu w-100 shadow show p-2"
                 x-cloak
                 x-show="isOpen"
                 style="position: absolute; z-index: 1050; max-height: 300px; overflow-y: auto; top: 100%; left: 0; right: 0;">
                <input
                    type="text"
                    class="form-control form-control-sm mb-2"
                    wire:model.live.debounce.300ms="search"
                    placeholder="Cari terminal..."
                    autocomplete="off"
                >

                @if(count($options))
                    @foreach($options as $option)
                        <button
                            type="button"
                            class="dropdown-item text-truncate"
                            wire:click="select('{{ $option['id'] }}')"
                            @click="isOpen = false"
                            wire:key="terminal-option-{{ $option['id'] }}"
                            title="{{ $option['name'] }}"
                        >
                            {{ $option['name'] }}
                        </button>
                    @endforeach
                @else
                    <div class="dropdown-item disabled">Tidak ada hasil</div>
                @endif
            </div>
        @endunless

        <input type="hidden" name="{{ $name }}" wire:model="selected" value="{{ $selected ?? '' }}">

        @if($disabledReason)
            <div class="text-muted small mt-1">{{ $disabledReason }}</div>
        @endif

        @if($error)
            <div class="text-danger small mt-1">{{ $error }}</div>
        @endif
    </div>

    @if($selectedLabel && ! $disabled)
        <button type="button"
                class="btn btn-outline-danger btn-sm ms-1"
                wire:click="clear"
                title="Batal pilihan terminal"
                style="min-width: 36px;">
            <i class="bi bi-x-lg"></i>
        </button>
    @endif
</div>

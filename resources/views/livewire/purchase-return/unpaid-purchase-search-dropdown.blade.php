@php($options = $this->filteredOptions)

<div class="d-flex"
     x-data="{
        open: @entangle('open'),
        top: -9999,
        left: -9999,
        width: 0,
        init() {
            if (this.open) {
                this.$nextTick(() => this.updatePosition());
            }
            this.$watch('open', value => {
                if (value) {
                    this.$nextTick(() => {
                        this.updatePosition();
                    });
                }
            });
        },
        updatePosition() {
            let rect = this.$refs.trigger.getBoundingClientRect();
            this.top = rect.bottom + window.scrollY;
            this.left = rect.left + window.scrollX;
            this.width = rect.width;
        },
        toggleDropdown() {
             this.$wire.toggleDropdown();
        }
     }"
     @scroll.window="if(open) open = false" 
     @resize.window="if(open) open = false"
     >
    <div class="flex-grow-1 position-relative" wire:click.away="closeDropdown">
        <button type="button"
                x-ref="trigger"
                class="form-control form-control-sm d-flex justify-content-between align-items-center text-start"
                @click="toggleDropdown">
            <span class="{{ $selectedLabel ? '' : 'text-muted' }} text-truncate me-2" title="{{ $selectedLabel ?? $placeholder }}">
                {{ $selectedLabel ?? $placeholder }}
            </span>
            <i class="bi {{ $open ? 'bi-chevron-up' : 'bi-chevron-down' }} flex-shrink-0"></i>
        </button>

        @if($open)
            <div class="dropdown-menu shadow show p-2"
                 x-init="updatePosition()"
                 x-bind:style="`position: absolute; top: 100%; left: 0; width: 100%; z-index: {{ $zIndex }}; max-height: 250px; overflow-y: auto; display: block;`"
                 style="position: absolute; top: 100%; left: 0; width: 100%; z-index: {{ $zIndex }}; max-height: 250px; overflow-y: auto;">
                <input
                    type="text"
                    class="form-control form-control-sm mb-2"
                    wire:model.live.debounce.300ms="search"
                    placeholder="Cari nota..."
                    autocomplete="off"
                    autofocus
                >

                @if(count($options))
                    @foreach($options as $option)
                        <button
                            type="button"
                            class="dropdown-item text-truncate py-1 px-2"
                            style="font-size: 0.875rem;"
                            wire:click="select('{{ $option['id'] }}')"
                            wire:key="purchase-option-{{ $option['id'] }}"
                            title="{{ $option['name'] }}"
                        >
                            {{ $option['name'] }}
                        </button>
                    @endforeach
                @else
                    <div class="dropdown-item disabled text-muted text-center small">Tidak ada hasil</div>
                @endif
            </div>
        @endif

        <input type="hidden" name="{{ $name }}" value="{{ $selected ?? '' }}">

        @if($error)
            <div class="text-danger small mt-1">{{ $error }}</div>
        @endif
    </div>
</div>

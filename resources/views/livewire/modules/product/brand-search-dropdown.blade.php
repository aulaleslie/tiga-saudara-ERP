@php($options = $this->filteredOptions)

<div class="d-flex">
    <div class="flex-grow-1 position-relative" wire:click.away="closeDropdown">
        <button type="button"
                class="form-control d-flex justify-content-between align-items-center text-start"
                wire:click="toggleDropdown">
            <span class="{{ $selectedLabel ? '' : 'text-muted' }}">
                {{ $selectedLabel ?? $placeholder }}
            </span>
            <i class="bi {{ $open ? 'bi-chevron-up' : 'bi-chevron-down' }}"></i>
        </button>

        @if($open)
            <div class="dropdown-menu w-100 shadow show p-2"
                 style="position: absolute; z-index: 1050; max-height: 300px; overflow-y: auto; top: 100%; left: 0; right: 0;">
                <input
                    type="text"
                    class="form-control form-control-sm mb-2"
                    wire:model.live.debounce.300ms="search"
                    placeholder="Cari merek..."
                    autocomplete="off"
                >

                @if(count($options))
                    @foreach($options as $option)
                        <button
                            type="button"
                            class="dropdown-item"
                            wire:click="select('{{ $option['id'] }}')"
                            wire:key="brand-option-{{ $option['id'] }}"
                        >
                            {{ $option['name'] }}
                        </button>
                    @endforeach
                @else
                    <div class="dropdown-item disabled">Tidak ada hasil</div>
                @endif
            </div>
        @endif

        <input type="hidden" name="{{ $name }}" value="{{ $selected ?? '' }}">

        @if($error)
            <div class="text-danger small mt-1">{{ $error }}</div>
        @endif
    </div>

    @if($clearable && $selected !== null && $selected !== '')
        <button type="button"
                class="btn btn-outline-secondary btn-sm ms-1"
                wire:click="clearSelection"
                data-toggle="tooltip"
                title="Hapus pilihan">
            <i class="bi bi-x-lg"></i>
        </button>
    @endif

    @if($allowCreate)
        <button type="button"
                class="btn btn-outline-primary btn-sm ms-1"
                onclick="Livewire.dispatch(@js($modalEvent))"
                data-toggle="tooltip"
                title="Tambah merek baru">
            <i class="bi bi-plus-circle"></i>
        </button>
    @endif
</div>

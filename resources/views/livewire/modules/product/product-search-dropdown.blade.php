@php($options = $this->search_results)

<div class="d-flex w-100">
    @if($name)
        <input type="hidden" name="{{ $name }}" value="{{ $selected }}">
    @endif

    <div class="flex-grow-1 position-relative" wire:click.away="closeDropdown">
        <button type="button"
                class="form-control d-flex justify-content-between align-items-center text-start"
                wire:click="toggleDropdown">
            <span class="{{ $selectedLabel ? '' : 'text-muted' }} text-truncate mr-2" title="{{ $selectedLabel ?? $placeholder }}">
                {{ $selectedLabel ?? $placeholder }}
            </span>
            <i class="bi {{ $open ? 'bi-chevron-up' : 'bi-chevron-down' }}"></i>
        </button>

        @if($open)
            <div class="dropdown-menu w-100 shadow show p-2"
                 style="position: absolute; z-index: 1050; max-height: {{ $conversionCandidatesOnly ? '420px' : '300px' }}; overflow-y: auto; top: 100%; left: 0; right: 0;">
                <input
                    type="text"
                    class="form-control form-control-sm mb-2"
                    wire:model.live.debounce.300ms="search"
                    placeholder="Cari produk..."
                    autocomplete="off"
                >

                @if(count($options))
                    @foreach($options as $option)
                        <button
                            type="button"
                            class="dropdown-item text-wrap"
                            style="white-space: normal; line-clamp: 2; display: -webkit-box; -webkit-line-clamp: 2; -webkit-box-orient: vertical; overflow: hidden;"
                            wire:click="select({{ $option->id }})"
                            wire:key="product-option-{{ $option->id }}"
                            title="{{ $option->product_name }}"
                        >
                            {{ $option->product_name }}
                        </button>
                    @endforeach
                    @if($query_count >= $how_many)
                        <div class="dropdown-item text-center">
                            <a wire:click.prevent="loadMore" href="#" class="btn btn-sm btn-primary">Memuat lebih</a>
                        </div>
                    @endif
                @elseif($search)
                    <div class="dropdown-item disabled">Tidak ada hasil</div>
                @endif
            </div>
        @endif

    </div>

    <div class="ms-1" style="min-width: 36px;">
        @if($selectedLabel)
            <button type="button" class="btn btn-outline-danger btn-sm" wire:click="clearSelection">&times;</button>
        @endif
    </div>
</div>


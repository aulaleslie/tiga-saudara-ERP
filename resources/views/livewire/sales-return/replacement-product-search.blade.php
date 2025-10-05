<div class="position-relative">
    <div class="input-group">
        <input type="text"
               class="form-control"
               wire:model.live.debounce.500ms="query"
               wire:focus="$set('isFocused', true)"
               wire:blur="resetQueryAfterDelay"
               wire:keydown.escape="resetQuery"
               placeholder="Cari produk pengganti...">
    </div>

    @if($isFocused)
        <div class="card position-absolute mt-1 w-100" style="z-index: 10;">
            <div class="card-body p-0">
                @if(count($search_results) > 0)
                    <ul class="list-group list-group-flush">
                        @foreach($search_results as $result)
                            <li class="list-group-item list-group-item-action">
                                <a href="#" wire:click.prevent="selectProduct({{ $result->id }})">
                                    {{ $result->product_code }} | {{ $result->product_name }}
                                </a>
                            </li>
                        @endforeach
                        @if($query_count > $how_many)
                            <li class="list-group-item text-center">
                                <a href="#" class="btn btn-sm btn-outline-primary" wire:click.prevent="loadMore">
                                    Muat lebih banyak
                                </a>
                            </li>
                        @endif
                    </ul>
                @elseif($query)
                    <div class="p-3 text-center text-muted">Produk tidak ditemukan.</div>
                @endif
            </div>
        </div>
    @endif
</div>

<div class="input-group d-flex justify-content-center">
    <input wire:model="unit_price.{{ $cart_item->id }}" style="min-width: 40px;max-width: 90px;" type="text"
           class="form-control" min="0" wire:blur="updatePrice('{{ $cart_item->rowId }}', {{ $cart_item->id }})">
</div>

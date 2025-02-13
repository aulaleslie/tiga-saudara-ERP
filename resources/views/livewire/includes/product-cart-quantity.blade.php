<div class="input-group d-flex justify-content-center">
    <input wire:model="quantity.{{ $cart_item->id }}" style="min-width: 40px;max-width: 90px;" type="number"
           class="form-control text-right" value="{{ $cart_item->qty }}" min="1"
           wire:blur="updateQuantity('{{ $cart_item->rowId }}', {{ $cart_item->id }})">
</div>

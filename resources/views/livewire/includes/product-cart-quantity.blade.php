@if($cart_item->options->serial_number_required)
    <div class="input-group d-flex justify-content-center">
        <input type="number"
               class="form-control text-right"
               value="{{ count($cart_item->options->serial_numbers) }}"
               disabled>
    </div>
@else
    <div class="input-group d-flex justify-content-center">
        <input wire:model="quantity.{{ $cart_item->id }}"
               style="min-width: 40px; max-width: 90px;"
               type="number"
               class="form-control text-right"
               value="{{ $cart_item->qty }}"
               min="1"
               wire:blur="updateQuantity('{{ $cart_item->rowId }}', {{ $cart_item->id }})">
    </div>
@endif

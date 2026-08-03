## Why

In the Sales and Purchase create/edit carts, a correct displayed `Total Baris` can open with a stale or truncated raw value in its editor. For example, a Rp46.500 line can display as `4650` when the user first opens the editor, creating a risk that an unintended lower total is committed.

## What Changes

- Make opening a `Total Baris` editor reliably populate the canonical full current line total.
- Apply the behaviour consistently to standard Sales and Purchase cart rows in create and edit flows.
- Preserve the existing final-line-total calculation, validation, tax, discount, and manual-price authority rules.
- Add regression coverage for an initial total with a trailing zero (for example `46500`) and for a user-entered replacement value.

## Capabilities

### New Capabilities

- `cart-line-total-editor-initialization`: Reliable initialization of the editable Total Baris field in Sales and Purchase carts.

### Modified Capabilities

- `purchase-creation`: Purchase cart rows must expose the authoritative current final line total when the user opens its editor.
- `sales-manual-line-price-authority`: Sales Total Baris editing must begin with the authoritative current final line total before a user commits a manual value.

## Impact

- Affects `app/Livewire/Purchase/ProductCart.php`, `app/Livewire/Sale/ProductCart.php`, and their Livewire Blade cart views.
- Requires focused Livewire/browser-facing regression coverage for Purchase and Sales create/edit behaviour.
- No database, API, or migration changes.

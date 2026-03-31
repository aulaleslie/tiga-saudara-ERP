## Why

Products are currently being automatically assigned a default tax (such as PPN 11%) during creation or when added to a purchase/sale cart if the business is in PKP (Taxable) mode. This happens even when the user has not explicitly selected a tax, leading to incorrect calculations and potential compliance issues. Users should have full control over tax application, and the system should not assume a default unless explicitly configured or selected.

## What Changes

The automatic tax assignment logic will be removed from both client-side (Alpine.js) and server-side (Livewire) cart components. Additionally, hardcoded tax defaults in the CSV import process will be eliminated. Products will remain taxless unless the user explicitly chooses a tax during creation, import, or within the transaction cart.

## Capabilities

### Modified Capabilities
- `purchase-management`: Update purchase cart to stop auto-assigning taxes to new items in PKP mode.
- `sale-management`: Update sale cart to stop auto-assigning taxes to new items in PKP mode.
- `product-management`: Remove hardcoded tax defaults from CSV imports and ensure Quick Add modal doesn't trigger auto-assignment via cart interaction.

## Impact

- **UI Components:** `product-cart-alpine.blade.php`, `Purchase/ProductCart.php`, `Sale/ProductCart.php`.
- **Backend Services:** `ProductController` (CSV Import logic).
- **Validation:** Transactions in PKP mode may now require explicit tax selection before saving, which is the desired compliant behavior.

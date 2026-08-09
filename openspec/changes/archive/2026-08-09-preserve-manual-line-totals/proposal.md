## Why

Editing a Purchase or Sales `Total Baris` can silently change the committed amount when the requested total does not divide evenly by the quantity. Both carts round the derived unit price to two decimal places and then rebuild the total from that rounded price; for example, Rp1.460.000 across 1.200 units becomes Rp1.460.004. The same loss of authority can affect PKP tax-included and tax-exclusive documents, making a user-entered amount, the document total, and tax allocation diverge.

## What Changes

- Make a valid manually committed `Total Baris` authoritative for standard, non-bundled Purchase and Sales cart rows, even when its derived two-decimal unit price cannot multiply back to the exact total.
- Preserve the exact committed line total through the cart and document persistence paths; the displayed/stored unit price remains a rounded derived value.
- Allocate PKP line pre-tax subtotal and tax amount from the authoritative committed total in both tax-included and tax-exclusive modes, so their sum equals the committed total exactly.
- Retain existing non-PKP behavior: no tax is retained or reversed out of a manually committed Sales total.
- Add regression coverage for high/non-divisible quantities, including the reported 1.200-unit Rp1.460.000 case, for Purchase and Sales and for PKP tax modes.

## Capabilities

### New Capabilities

- `purchase-manual-line-total-authority`: Purchase cart rows preserve a valid manually committed total and allocate any applicable tax without precision drift.

### Modified Capabilities

- `sales-manual-line-price-authority`: Manual Sales line-total edits preserve the exact committed total across non-PKP and PKP tax modes despite two-decimal unit-price rounding.

## Impact

- Affects `app/Livewire/Purchase/ProductCart.php` and `app/Livewire/Sale/ProductCart.php`, plus their focused Livewire feature tests.
- Affects cart subtotal/tax metadata consumed by Purchase and Sales create/edit persistence, document totals, and tax reporting.
- No public API, database schema, or historical-data migration is expected; the existing two-decimal `unit_price` remains a derived approximation while persisted line monetary totals remain authoritative.

## Why

POS product search already identifies bundle-parent products, but the sell flow cannot let cashiers choose a bundle or carry bundle metadata through checkout. This prevents POS from selling bundled products with the same commercial and stock behavior expected by the business, especially when parent and child products must independently respect `stock_managed`.

## What Changes

- Add bundle selection to the POS sell flow when a cashier adds a bundle-parent product.
- Extend the POS cart line and snapshot contract to store bundle selection state and bundled child item metadata.
- Make POS checkout bundle-aware so the selected parent product and bundled child products are persisted as part of the sale.
- Apply checkout stock validation and deduction independently to the parent product and each bundled child product based on each product's `stock_managed` flag.
- Preserve the existing POS flow for normal products and for bundle-parent products when the cashier explicitly skips bundle selection.

## Capabilities

### New Capabilities
- `pos-bundle-selection-checkout`: Supports selecting bundles in the POS sell flow, carrying bundle metadata in the POS cart snapshot, and posting bundle-aware POS checkouts with stock deduction controlled by each product's `stock_managed` flag.

### Modified Capabilities
- None.

## Impact

- Affected POS UI: product search/add flow in `Modules/Pos/Resources/views/sell.blade.php`
- Affected POS APIs: product bundle lookup and cart line creation/update contracts under `Modules/Pos/Http/Controllers/PosSellController.php`
- Affected POS services: cart snapshot building, totals calculation, and checkout posting in `Modules/Pos/Services/*`
- Affected sales posting records: POS checkout integration with `SaleDetails`, bundle item persistence, dispatch details, and stock transactions

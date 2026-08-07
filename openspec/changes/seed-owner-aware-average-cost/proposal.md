## Why

The current sales-HPP seeding command can overwrite average costs across businesses without distinguishing a shared bootstrap value from a business's own later HPP. It also does not reliably repair missing `product_prices` rows or zero average costs for every business. The command needs owner-aware seeding so each business has a usable average cost while subsequent special-company HPP remains isolated.

## What Changes

- Change `product:seed-average-cost-from-sales-hpp` to process every stock-managed product and establish its shared HPP baseline from the only sales-import source owners in explicit priority order: Perdana, Top IT, then CV Tiga Nusa.
- Fill only missing, null, or zero `average_purchase_price` values for every product × setting row from that baseline.
- Create missing `product_prices` rows for target businesses when a baseline is available, preserving available same-product metadata.
- Apply Top IT and CV Tiga Nusa's latest eligible HPP only to their own setting rows after baseline filling.
- Report products with no eligible baseline as unresolved without fabricating a cost.
- Preserve dry-run-by-default and explicit `--write` behavior.
- Keep purchase-import and `last_purchase_price` behavior out of scope.

## Capabilities

### New Capabilities

<!-- None. -->

### Modified Capabilities

- `sales-hpp-average-cost-seeding`: Change HPP average-cost seeding to fill missing cross-business values from a prioritized baseline and retain owner-specific special-company costs.

## Impact

- Affected command: `Modules/Product/Console/SeedAverageCostFromSalesHppCommand.php`.
- Affected persistence: `product_prices.average_purchase_price` and creation of missing `product_prices` rows.
- Affected tests: sales-HPP average-cost command feature coverage.
- No API, migration, purchase-import, or `last_purchase_price` changes are required.

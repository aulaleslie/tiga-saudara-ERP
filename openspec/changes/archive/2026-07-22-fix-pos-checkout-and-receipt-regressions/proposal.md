## Why

Recent POS checkout and packed-pricing changes left several cashier-facing regressions: checkout can post without a distinct final confirmation, valid zero and partial-down-payment debt sales cannot complete through the staged-payment UI, and packed receipt prices are displayed at 100 times their Rupiah value with placeholder unit initials. Large receipt totals can also wrap or clip unpredictably on the 72 mm thermal layout, reducing confidence in payment and receipt accuracy.

## What Changes

- Add an explicit final transaction confirmation before the irreversible checkout-finalize request, distinct from confirmation of an individual staged payment.
- Make debt checkout support zero or partial down payments below the grand total while retaining the selected customer, payment term, debt mode, and any staged payments through authorization and finalization.
- Present debt-specific final confirmation information, including the customer, term, paid amount, and outstanding amount.
- Convert packed breakdown prices from internal minor units to Rupiah exactly once before receipt formatting.
- Print the actual snapshotted conversion and base-unit labels instead of hardcoded `[K]` and first-letter placeholders such as `[P]`.
- Keep large line totals, grand totals, payment amounts, and change amounts intact and right-aligned within the thermal receipt width.
- Add regression coverage for checkout state transitions, debt checkout paths, packed receipt values and units, and large monetary values.

## Capabilities

### New Capabilities

None.

### Modified Capabilities

- `pos-checkout-confirmation`: Require a final transaction-level confirmation before checkout finalization, including paths that reach finalization without committing another payment stage.
- `pos-debt-checkout`: Make zero and partial-down-payment debt checkout operable through the staged UI and preserve debt context until posting.
- `pos-receipt`: Require packed receipt breakdowns to use correctly scaled prices and actual snapshotted unit names.
- `pos-professional-receipt`: Require monetary values to remain legible and unbroken within the thermal receipt layout.

## Impact

- POS staged-payment state and modal behavior in `public/js/pos-staged-payment.js` and the POS sell modal Blade views.
- Checkout request construction and existing supervisor-approval retry behavior; no new endpoint is expected.
- Packed pricing metadata and transaction-line snapshots produced by `Modules/Pos/Services/PosCartService.php` and `PosTransactionSnapshotMapper.php`.
- Receipt reconstruction and thermal formatting in `PosReceiptService.php` and `Modules/Pos/Resources/views/receipt.blade.php`.
- POS feature/unit tests and frontend-oriented regression coverage. Existing Sale posting, payment collection, stock allocation, split-owner behavior, and database price storage remain unchanged.

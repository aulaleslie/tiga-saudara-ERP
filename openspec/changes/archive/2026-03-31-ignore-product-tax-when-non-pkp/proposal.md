## Why

Currently, if the settings indicate the business is non-PKP (`is_pkp = false`), the system still saves tax data and computes tax amounts if a product row in the frontend cart has a tax assigned. This creates unexpected tax entries and inflates totals on purchases and sales for non-PKP entities. This change strictly enforces non-PKP behavior by explicitly ignoring tax identifiers and calculating zero taxes at the backend controller level when the business is non-PKP.

## What Changes

- Forces `tax_id` to `null` and calculated tax amounts to `0` when creating or updating `Purchase` rows if `is_pkp` is false.
- Forces `tax_id` to `null` and calculated tax amounts to `0` when creating or updating `Sale` rows if `is_pkp` is false.
- Provides an ironclad guard in the controller layer to decouple backend database writes from frontend cart state anomalies for taxes.

## Capabilities

### New Capabilities

### Modified Capabilities
- `tax-assignment`: Update the tax assignment requirements to define that when `is_pkp` is false, the system must actively ignore any incoming tax identifiers associated with products during purchase and sales row generation, enforcing zero tax.

## Impact

- `Modules\Purchase\Http\Controllers\PurchaseController` (specifically `store` and `update` logic).
- `Modules\Sale\Http\Controllers\SaleController` (or its backing `SaleService`).
- Tax calculations on Cartesian insertion.

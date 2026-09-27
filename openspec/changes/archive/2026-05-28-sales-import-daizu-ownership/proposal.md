## Why

Sales import currently resolves sale ownership from Tag or product markers while stock ownership can fall back to purchase history per product. For KEDELE, KEDELAI, and RAGI rows this can split a sale document, ProductPrice row, dispatch location, product stock decrement, and inventory Transaction across different settings, even though purchase import now treats these products as fully owned by Daizu Kedelai.

This change aligns sales import with the Daizu purchase ownership rule and makes setup or duplicate ambiguity explicit before more kedelai sales files are imported.

## What Changes

- Add a Daizu product detection rule to sales import using the same whole-word product-name match as purchase import: `KEDELE`, `KEDELAI`, or `RAGI`.
- Route Daizu-matched sales rows to the Daizu Kedelai setting for sale document ownership, regardless of Tag, product marker, or historical fallback.
- Route Daizu-matched stock decrements to Daizu Kedelai locations and inventory Transactions, bypassing marker and history rules.
- Use CSV `Gudang` as the dispatch stock location within Daizu Kedelai when provided; otherwise use the Daizu default location.
- Fail Daizu-matched rows explicitly when the Daizu Kedelai setting or required Daizu location cannot be found.
- Guard duplicates so legacy non-Daizu sales for the same Daizu-matched invoice are treated as conflicts instead of silently allowing a second Daizu sale.
- Add focused sales import tests for owner alignment, stock decrement alignment, duplicate conflict handling, missing setup failures, and whole-word matching.

## Capabilities

### New Capabilities
- `sales-import-daizu-ownership`: Sales import ownership and stock segregation rules for Daizu Kedelai products.

### Modified Capabilities
- None.

## Impact

- Affected service: `Modules/Sale/Services/SalesImportService.php`.
- Affected import flow: sales upload staging rows, invoice grouping, duplicate detection, sale creation, ProductPrice updates, dispatch creation, product stock decrements, and inventory Transaction logging.
- Affected tests: sales import feature tests and shared import stock location tests.
- Related reference behavior: `openspec/specs/purchase-import-daizu-ownership/spec.md` and `Modules/Purchase/Services/PurchaseImportService.php`.
- No database schema changes are expected.

## Why

Transfer entry currently rejects a product that has valid stock at an authorized origin when the product catalogue record belongs to another business, even though origin-location stock is the authoritative inventory boundary. Separately, production MySQL lost the intended zero defaults on legacy dispatch counters, causing otherwise valid destination-optional drafts to fail while their lines are inserted.

## What Changes

- Make stock-transfer barcode, conversion-barcode, serial, and deliberate product-search eligibility depend on authoritative stock or serial availability at the selected authorized origin, without requiring the product catalogue `setting_id` to equal the active tenant.
- Preserve tenant isolation by continuing to authorize the origin location against the active tenant and never searching stock outside that selected origin.
- Keep condition, stock-management, active-product, serialized availability, ambiguity, and stock-visibility protections intact.
- Add an additive schema repair that restores `DEFAULT 0` for all five non-null `transfer_products` dispatch quantity counters on MySQL-compatible databases.
- Verify that new draft lines may omit dispatch-only fields and save successfully with or without a destination.
- Add focused regression coverage for cross-catalogue origin stock discovery, unauthorized-location isolation, serialized resolution, and dispatch-counter defaults.

## Capabilities

### New Capabilities

- None.

### Modified Capabilities

- `stock-transfer-entry-scanning`: Define selected authorized origin stock and serial location—not product catalogue ownership—as transfer-entry eligibility while retaining tenant and visibility boundaries.
- `stock-transfer-approval-lifecycle`: Require undispatched transfer lines to persist with canonical zero-valued dispatch counters through database defaults.

## Impact

- Affects `TransferScanResolverService`, transfer-entry search/selection paths that reuse it, and focused transfer-entry tests.
- Adds an Adjustment module migration altering defaults on existing `transfer_products` columns without rewriting quantities or transfer history.
- Affects MySQL production behavior directly; SQLite-focused tests require complementary schema/default assertions appropriate to each driver.
- No API removal, inventory mutation, workflow-version change, or historical transfer reinterpretation is introduced.

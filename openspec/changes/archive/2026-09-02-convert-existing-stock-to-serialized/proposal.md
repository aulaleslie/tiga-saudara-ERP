## Why

Products that were created without serial tracking cannot currently be converted safely after stock exists: the product editor locks the flag, while directly changing it would leave available quantities without matching serial identities. The ERP needs a controlled, permission-gated operation that assigns serial numbers to every available unit across all businesses before enabling serial tracking.

## What Changes

- Add a dedicated product-stock conversion page available only to users with a new global conversion permission.
- Let an authorized user select one eligible, stock-managed, non-serialized product and scan serial numbers for all of its available normal and broken stock in one session.
- Present capped owner-level pools for PPN and Non-PPN stock while preserving the existing per-location quantities through deterministic automatic location allocation.
- Require all owner, tax, and condition pools to be complete before enabling a single final submission.
- Revalidate eligibility, stock quantities, and global serial uniqueness under database locks, then create every serial and enable serial tracking in one atomic transaction.
- Prevent generic product editing from bypassing the controlled conversion path when stock or serial dependencies exist.
- Record a focused conversion audit trail and protect the final submission from duplicate processing.
- Document historical-sale and operational limitations separately without expanding this change into historical transaction rewriting or legacy-return support.

## Capabilities

### New Capabilities

- `existing-stock-serialization-conversion`: Permission-gated, scanner-driven, all-stock conversion of a non-serialized product into a serialized product across all owners and locations.

### Modified Capabilities

None.

## Impact

- Product management routes, permissions, controller/service layer, Blade or Livewire UI, and product update validation.
- `products`, `product_stocks`, `product_serial_numbers`, serial history/audit data, locations, settings, and default tax resolution.
- Existing purchase-receiving scanner interaction is a UI reference, but conversion uses stricter validation that rejects every previously registered serial.
- Focused feature tests for authorization, scan/pool validation, stock drift, atomic rollback, idempotency, and successful conversion; no full-scale regression suite is planned for this change.

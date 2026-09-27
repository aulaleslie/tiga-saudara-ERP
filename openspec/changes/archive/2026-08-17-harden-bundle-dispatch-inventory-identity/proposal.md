## Why

Standard Sales dispatch submission and approval each compute their authoritative state (outstanding demand, pending status) before opening a database transaction or locking the affected rows. Concurrent submissions can therefore both pass validation against the same outstanding quantity, and concurrent approvals can both pass the pending check and both apply stock/serial/notification effects. Separately, approval reads a product's *current* `stock_managed` flag rather than the value captured when the dispatch detail was submitted, so a classification change between submission and approval can silently switch a detail between inventory movement and non-stock audit acknowledgement.

## What Changes

- Make standard Sales dispatch submission atomic: lock the Sale row and recalculate authoritative outstanding demand (parent + bundle-component quantities minus existing pending/approved dispatch quantities) inside the same transaction that creates the pending dispatch, so two concurrent submissions cannot both reserve the same outstanding quantity.
- Make dispatch approval exactly-once: lock and re-check the dispatch row's pending status as the first step inside the approval transaction, before any stock, serial, notification, or Sale-status effect, so two concurrent approvals cannot both apply effects.
- Persist a server-authored inventory-routing snapshot on each dispatch detail at submission time (from the classification already resolved during submission) and have approval honor that snapshot instead of the live product flag, rejecting approval with an actionable conflict if the live classification no longer matches.

## Out of Scope

- No new centralized fulfillment-key builder: the existing `product_id + tax_id + bundle_id` aggregation is already applied consistently by both submission and dispatch-index code paths and is not implicated in any of the three races.
- No POS regression reconciliation: POS checkout finalize already has its own transaction-wide rollback and idempotent replay boundary; this change does not touch POS posting and does not require re-auditing existing POS test suites.
- No rewrite or backfill of completed historical dispatches or non-stock acknowledgement lifecycle behavior.

## Capabilities

### Modified Capabilities

- `sales-dispatch-exactly-once`: Atomic submission demand reservation, exactly-once locked approval, and immutable per-detail routing snapshot for standard Sales dispatch.
- `sales-dispatch-stock-gating`: Approval revalidates stock and routing under lock before deducting inventory, at most once.

## Impact

- Code impact is limited to `Modules/Sale/Http/Controllers/SaleController.php` (`storeDispatch()` and `approveDispatch()`), plus one additive nullable-column migration on `dispatch_details`.
- No customer-facing pricing, bundle composition, tax allocation, return, HPP, reporting, or POS posting changes.
- Tests are added directly against the changed methods (concurrency/race scenarios and classification-conflict scenarios); no full-suite run is required to validate this change, though `composer test:fresh-sqlite` remains available before release.

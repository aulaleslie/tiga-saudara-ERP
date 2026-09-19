# Proposal

## Why

Stock opname currently forces one active-setting-owned location per document, even when an operator physically counts inventory pooled across several warehouses. Supporting an explicit multi-location pool lets the physical result be compared with the combined system stock and lets approval distribute differences predictably across the selected locations.

## What Changes

- Replace the stock-opname single-location selection with a searchable multi-location selection of active, standard locations that is not filtered by the user's current active setting.
- Compare each entered good and damaged physical count with the corresponding sum across all selected locations, while preserving per-location baseline and current-stock evidence.
- Allocate shortages to non-PKP locations with the most relevant stock first, then to PKP locations; allocate surpluses to non-PKP locations with the least relevant stock first, then to PKP locations. Use ascending location ID as the deterministic final tie-breaker.
- Apply good and damaged differences independently and present the proposed per-location allocation before approval.
- Rework serialized reconciliation around the selected location pool, retaining exact serial provenance and using deterministic eligible destinations for new or incoming serials.
- Persist the selected location set and immutable per-location approval result while retaining read compatibility for historical single-location documents.
- Replace active-setting ownership as the stock-opname location scope with permission-aware access to every selected location, while continuing to reject inactive and consignment locations.
- Verify the change with focused stock-opname tests only; a full application test-suite run is outside this change's required verification.

## Capabilities

### New Capabilities

- None.

### Modified Capabilities

- `stock-opname-count-drafts`: Change the editor, baseline, and draft contract from one active-setting location to an authorized multi-location pool.
- `stock-opname-review-approval`: Change reconciliation, authorization, tax handling, serial behavior, approval posting, and audit evidence to operate across selected locations with deterministic PKP-last difference allocation.

## Impact

- Stock-opname Livewire state and its searchable location control.
- Adjustment persistence, including a normalized adjustment-to-location relation and a new version of the `count_draft` payload.
- Draft validation, baseline capture, authorization guards, reconciliation DTOs/views, approval locking and posting, notifications, and immutable approval results.
- Per-location `ProductStock`, aggregate product quantities, serial locations/conditions, inventory transactions, and stock notifications.
- Existing versioned single-location stock-opname documents remain readable and retain their historical behavior and evidence.

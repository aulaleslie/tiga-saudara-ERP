## Why

Stock transfer creation, editing, approval, dispatch, receiving, and return currently use inconsistent write paths and lifecycle guards, leaving edit broken, approval and mutation vulnerable to races, and cross-tenant returns broader than the actual taxed stock obligation. Warehouse operators also need scanner-first product entry with deterministic base-unit conversion and clear tax-allocation warnings instead of relying on text search and manual quantity-bucket entry.

## What Changes

- Unify stock-transfer create and edit behavior around one validated draft model, allowing mutation only in editable lifecycle states and enforcing origin-tenant authorization at the server boundary.
- Add scanner-first lookup for product barcodes, unit-conversion barcodes, and serial numbers while retaining debounced product-name/code search as a fallback.
- Normalize every scanned conversion into base-unit quantity and preserve enough scan context for the operator and audit history to understand the requested quantity.
- Preview non-tax-first allocation for non-serialized stock, warn when taxed stock will be consumed, and determine the authoritative allocation again from locked live stock at dispatch.
- Harden approval so only `stockTransfers.approval` grants approve/reject authority while still allowing a creator with that permission to approve their own transfer.
- Add rejection reasons, rejection acknowledgement back to draft, immutable revision/action history, approved-record immutability, and pre-dispatch archival with a reason.
- Make create/update, lifecycle transitions, dispatch, receiving, return dispatch, and return receipt atomic and concurrency-safe through authoritative revalidation and row locking.
- Change cross-tenant return behavior so non-tax quantities may remain at the destination while actually dispatched taxed quantities, including intentionally transferred broken-tax stock and exact taxed serials, must return to the origin before completion.
- Preserve historical transfer records and existing document numbers without destructive rewriting while defining compatible terminal-state handling.

## Capabilities

### New Capabilities

- `stock-transfer-entry-scanning`: Unified draft creation/editing, text and scanner lookup, base-unit normalization, serial capture, and operator-facing allocation previews.
- `stock-transfer-approval-lifecycle`: Permission-governed approval, rejection acknowledgement, revision history, approved immutability, archival, and concurrency-safe lifecycle transitions.
- `stock-transfer-inventory-movement`: Authoritative non-tax-first dispatch allocation, allocation-drift acknowledgement, atomic inventory/serial movement, receiving, and idempotent transition behavior.
- `stock-transfer-cross-tenant-tax-return`: Quantity- and serial-specific mandatory return obligations for taxed stock moved across tenants, with non-tax retention and completion rules.

### Modified Capabilities

None. The repository has historical permission and inventory specifications relevant as compatibility context, but no existing stock-transfer capability specification whose requirements are being changed.

## Impact

- Affects `Modules/Adjustment` transfer entities, migrations, routes, controller behavior, DataTable actions, and transfer create/edit/show views.
- Affects `app/Livewire/Transfer` and its Blade views, replacing the incompatible create/edit form split with a shared state and persistence contract.
- Introduces transfer-focused services for lookup, allocation, lifecycle, dispatch/receiving, return obligations, and append-only action history.
- Reuses product barcode identity, unit-conversion, serial, location, stock, transaction, permission, and idempotency conventions without adding a new external dependency.
- Adds additive database structures/columns for lifecycle history, revisions, archival, scan context, allocation preview/confirmation, and actual return obligations; production MySQL/MariaDB and focused SQLite tests must remain supported.
- Expands automated coverage across Livewire entry/edit, permissions, tenant isolation, lifecycle races, barcode/conversion/serial scanning, mixed tax allocation, dispatch drift, rollback, and cross-tenant tax-only return flows.

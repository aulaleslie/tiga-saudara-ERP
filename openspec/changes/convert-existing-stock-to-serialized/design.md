## Context

The product editor currently disables changes to `serial_number_required` when `product_stocks.quantity` is positive, but the backend update request does not provide a complete transition guard. Stock is stored per location in normal/broken and PPN/Non-PPN buckets, while every serialized unit requires a `product_serial_numbers` row with a concrete location, tax identity, condition, and lifecycle status. Downstream POS, transfer, adjustment, purchase, return, bundle, and consignment paths branch on the live product flag, so enabling it before serial identity is complete creates an inconsistent product.

The intended operational volume is at most about 100 units per product. Users will scan all serials on one page and accept loss of unsaved scans after refresh or connection failure. Delivery is prioritized over persisted conversion drafts or broad historical remediation.

## Goals / Non-Goals

**Goals:**

- Convert all available normal and broken stock across every setting and location in one atomic operation.
- Provide a fast scanner interaction based on purchase receiving while enforcing conversion-specific global uniqueness and capacity rules.
- Let the user work with owner-level PPN/Non-PPN and normal/broken pools while preserving original per-location stock distribution internally.
- Protect the operation with a dedicated permission, locked server-side revalidation, rollback, repeat-submission safety, and focused tests.

**Non-Goals:**

- Persisting scan drafts or recovering browser state after refresh/disconnection.
- Assigning serial identities to previously sold stock or rewriting historical transactions.
- Adding a legacy-return workflow, partial conversion, bulk multi-product conversion, or serialized-to-non-serialized reversal.
- Building for stock volumes materially above the stated approximately 100-unit limit.
- Running or planning the entire application test suite.

## Decisions

### Use a dedicated Product conversion surface and permission

Add `products.convert_existing_stock_to_serialized` to the established permission registry and protect the UI and all endpoints with it. The page may be linked from eligible product actions, but conversion authority remains distinct from ordinary `products.edit` because it reads and changes inventory across settings.

Alternative: reuse `products.edit`. Rejected because that permission is setting-oriented and too broad for a global inventory identity operation.

### Use four owner-level scan pools

Build page state from all `product_stocks` rows, joining each location to its owning setting. Aggregate each owner into normal Non-PPN, normal PPN, broken Non-PPN, and broken PPN pools. A segmented owner selector plus tax and condition toggles chooses the active pool. The browser maintains a page-wide serial set, caps each pool, shows progress, and disables final confirmation until all pools are exact.

Reuse the purchase-receiving interaction—Enter handling, removable badges, refocus, and derived counts—but not its receiving validator. Receiving intentionally permits SOLD/RETURNED reuse and checks within a product; conversion requires a new endpoint/policy that rejects any globally existing serial and duplicates across all page pools.

Alternative: expose one input per location. Rejected because the requested workflow deliberately disregards locations during scanning.

### Allocate serials deterministically to original locations

At final submission, sort locked stock rows by owner/setting and stable location ID. For each owner/tax/condition pool, consume submitted serials in scan order and allocate them up to each matching location bucket's exact capacity. This retains every original location total without asking the operator to locate individual units.

PPN serials receive the active `taxes.is_default = true` tax ID; Non-PPN serials receive `null`. Conversion is blocked if positive PPN stock exists but no active default tax can be resolved. Broken pools create serials with `is_broken = true` and broken lifecycle semantics consistent with existing serial handling.

Alternative: set one arbitrary owner location. Rejected because it would make serial availability disagree with `product_stocks` and break location-sensitive dispatch and transfer behavior.

### Keep scan preparation client-side and completion stateless

Do not add a conversion-session or persisted snapshot table. The GET response supplies current pool quantities, and the final request carries all scanned serials plus those expected pool totals. The backend treats client totals only as comparison input, locks and recomputes authoritative stock, and rejects drift.

At the expected volume, one request with at most roughly 100 serial strings is practical. Refreshing the page intentionally discards work.

Alternative: persist draft scans and snapshot hashes. Deferred because it adds lifecycle, cleanup, and recovery complexity without being necessary for initial delivery.

### Make the product lock the idempotency boundary

The final service starts a database transaction, locks the product first, then locks all product stock rows in deterministic order. It rechecks authorization, product eligibility, active stock dependencies, whole-number/nonnegative bucket consistency, default tax availability, exact submitted pool counts, and database-wide serial uniqueness. It then creates serials, records audit/history, and flips `serial_number_required` last.

A concurrent or retried request waits on the product lock and then observes the serialized flag or existing serials, so it exits without duplication. This avoids a new idempotency persistence layer.

### Reuse existing audit primitives

Add a conversion-specific serial history event and record the actor, assigned location, and reason through the existing serial-history service. Record location-level zero-quantity inventory transaction/audit entries only if existing transaction conventions support a classification-only event without corrupting quantity reporting; otherwise serial histories plus the product activity/audit convention are authoritative. Do not fabricate received-note or consignment lineage.

Alternative: introduce a conversion header/detail schema. Deferred to avoid another storage layer for a one-time, bounded operation.

### Block unsafe active dependencies conservatively

The eligibility service centralizes checks used by page load and final execution. It blocks known pending stock-moving documents for the product, existing serial rows, fractional/negative/internally inconsistent buckets, and already-serialized products. The implementation should reuse existing status constants and dependency patterns rather than invent a global workflow engine.

The generic product update request gains a server-side false-to-true guard whenever stock or serial dependencies exist, ensuring this service is the sole stocked conversion path.

## Risks / Trade-offs

- [Automatic location assignment may not match the physical placement of a specific scanned unit] → Preserve exact per-location counts deterministically and document the operational limitation separately.
- [Stock can change while scanning] → Lock and recompute at submission; reject the whole request with no partial writes.
- [Browser refresh loses up to approximately 100 scans] → Accept explicitly for the MVP and keep scanner entry fast; no draft persistence.
- [A global serial may exist under another product even though receiving validation would accept the input] → Use database-wide validation both during scanning and inside the transaction.
- [PPN quantities do not encode a concrete tax ID] → Resolve the active default tax and block conversion if none exists.
- [Unknown pending workflow variants may exist] → Cover directly related, known stock-moving models with focused checks and retain final stock drift validation as defense in depth.
- [Changing the live product flag affects drafts that captured old classification] → Block known active dependencies and flip the flag only after every serial is ready.

## Migration Plan

1. Register and seed the dedicated permission without automatically granting it to ordinary product editors; existing all-permission administrative roles follow current seeding conventions.
2. Deploy the conversion eligibility, validation, UI, and atomic execution path together with the generic edit guard.
3. No inventory backfill runs automatically. Authorized users opt into conversion one product at a time.
4. If deployment must be rolled back before any conversion, remove the UI/routes while preserving harmless permission metadata and audit events. Products already converted must remain serialized; rollback SHALL NOT delete their serial identities or revert their flag automatically.

## Open Questions

None for the initial delivery. Historical sales, legacy returns, physical-location certainty, and future persisted scan recovery are captured in `potential-issues.md` for later consideration.


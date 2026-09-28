# Design

## Context

`PosReceiptService` builds bundle composition from two quantity contracts. Persisted `sale_bundle_items` and dispatch data contain total component quantities already scaled during checkout posting. Transaction `line_meta.bundle_items`, however, stores each component's quantity per bundle. The current snapshot fallback maps that per-bundle value directly to display output, causing understated quantities when the parent line quantity exceeds one. Both receipt rendering and transaction detail reuse this composition service; draft receipts build equivalent snapshot composition separately.

## Goals / Non-Goals

**Goals:**

- Normalize snapshot-derived component quantities to total sold quantities at the receipt-service boundary.
- Preserve persisted composition as the authoritative total when available.
- Keep completed receipt, reprint, draft/loaded receipt, and transaction-detail output consistent.

**Non-Goals:**

- Changing cart, checkout, Sales posting, dispatch, stock, serial assignment, or persisted historical data.
- Redesigning receipt markup or styling.
- Introducing schema or API changes.
- Requiring full-suite verification.

## Decisions

### Scale only snapshot-derived composition

Pass the parent transaction-line quantity into snapshot composition reconstruction and calculate each displayed quantity as `parent quantity × component quantity per bundle`. This conversion belongs at the boundary where per-bundle snapshot data becomes customer-facing composition.

Persisted Sales/dispatch composition continues to pass through unchanged because posting has already scaled it to the dispatched total. Multiplying all composition after source selection was rejected because it would double persisted totals.

### Use one snapshot-normalization rule across receipt paths

Completed fallback, draft/loaded receipt construction, and transaction detail must use the same parent-scaling rule. A small shared receipt-service helper or equivalent centralized mapping is preferred over duplicating arithmetic in each path, provided it preserves current output shape.

### Preserve presentation and operational behavior

The Blade receipt remains unchanged. Only the numeric `qty` supplied in `bundle_composition` changes, so layout, labels, prices, and serial placement remain stable. Checkout and dispatch services are not modified because focused inspection and existing tests confirm their quantities are already scaled correctly.

### Verify through focused regressions

Add focused tests around `PosReceiptService` and the existing POS bundle receipt/detail coverage for parent quantity 2 with component multipliers 1 and 2. Retain or extend a persisted-composition case to prove totals are not multiplied twice. Run only these focused tests; a full-suite run is outside this change's verification scope.

## Risks / Trade-offs

- [Historical snapshots may use `qty` instead of `quantity`] → Preserve the existing fallback field precedence and apply scaling after resolving either field.
- [A persisted total could accidentally enter the snapshot path] → Source selection remains persisted-first; focused coverage asserts persisted quantities are unchanged.
- [Receipt and detail paths could diverge later] → Centralize snapshot quantity normalization in the receipt service and cover both consumers.

## Migration Plan

No data migration is required. Deploy the service-level correction and focused regression tests. Rollback consists of reverting the service mapping change; no stored records are altered.

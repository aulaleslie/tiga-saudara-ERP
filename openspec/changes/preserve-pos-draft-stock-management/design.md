## Context

`PosCartService` captures `stock_managed` on a newly added product line, and `FinalizePosCheckoutService` deliberately sends only stock-managed parents and components to `ResolvePosStockAllocationsService`. POS drafts are persisted and later rehydrated through `PosTransactionSnapshotMapper`; its line metadata currently omits `stock_managed`, so a loaded line has no classification. The checkout builder defaults an absent parent classification to stock-managed, producing a false `STOCK_UNAVAILABLE` mismatch for services with no inventory records.

The change is limited to preserving correct draft cart semantics. The established POS rule remains: a stock-managed parent is validated for inventory; a non-stock parent is not. Bundle component behavior is unchanged.

## Goals / Non-Goals

**Goals:**

- Preserve a POS line's stock-management classification through draft persistence and hydration.
- Keep non-stock products outside stock allocation and preflight shortage reporting after a draft is reloaded.
- Ensure legacy draft rows with no classification recover safely from the authoritative current product record.
- Verify both non-stock and stock-managed behavior across the save/load lifecycle.

**Non-Goals:**

- Change product inventory, dispatch, ownership, pricing, tax, serial, or bundle rules.
- Alter POS draft status, authorization, or payment sequencing.
- Introduce schema changes or backfill historical draft records.

## Decisions

### Store the classification in transaction line metadata

Persist the normalized boolean `stock_managed` alongside the existing cart-line metadata, then restore it when reconstructing the session cart. This is the smallest change that preserves the cart's original checkout semantics across the current draft snapshot boundary.

Alternative considered: add a dedicated database column. Rejected because line metadata already persists cart-only attributes, the value has no independent reporting/query requirement, and a migration is unnecessary for this correction.

### Resolve missing legacy metadata from the current product record

When hydrated metadata lacks `stock_managed`, obtain the boolean from the associated `Product` record in the existing batch product lookup. This avoids turning older non-stock drafts into stock-validated lines while retaining the existing default-to-stock-managed safety behavior only if the product cannot be resolved.

Alternative considered: default missing metadata to `false`. Rejected because it could let a historical stock-managed product bypass inventory validation.

### Preserve the checkout validator as the enforcement point

Do not special-case the mismatch dialog or suppress stock failures in the frontend. Correct hydration feeds the existing `buildStockResolverLines` behavior, so valid services pass and genuinely stock-managed products remain blocked when unavailable.

Alternative considered: exempt service-looking names or zero-stock products in preflight. Rejected because names and stock totals are not reliable indicators of product inventory policy.

## Risks / Trade-offs

- [A product's stock-management setting changes after a legacy draft is saved] → The fallback intentionally uses the current authoritative setting; newly persisted drafts retain their original normalized classification.
- [A product is deleted or unavailable while loading a legacy draft] → Retain the conservative stock-managed fallback so the checkout cannot silently bypass inventory validation.
- [Only direct checkout is tested] → Add coverage for draft persistence and rehydration before exercising checkout preflight/finalization.

## Migration Plan

1. Deploy the code-only change.
2. New and re-saved drafts carry the metadata immediately.
3. Existing drafts use current product classification when loaded; no data migration is needed.
4. Rollback is a code rollback. Historical draft data remains compatible because the extra JSON key is ignored by older code.

## Open Questions

None. The required behavior and safe legacy fallback are established by the observed cart snapshot and checkout validation flow.

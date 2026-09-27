## Context

POS checkout stores one checkout and receipt but may split generated Sales, payments, and Dispatches by source setting, location, and tax bucket. Stock-managed content obtains that source from the ordered POS sales-location configuration through the stock allocator. Non-stock content currently bypasses allocation, but standalone parents use the terminal setting and stockless bundle components use the first non-PKP source. The inline POS poster marks Sales dispatched and approves a Dispatch immediately, but does not persist a non-stock DispatchDetail.

The required rule is deliberately narrower than a service-completion workflow: POS checkout remains immediate and every generated Sale is fully dispatched. A non-stock DispatchDetail is an auditable proof of POS fulfilment, never inventory demand.

## Goals / Non-Goals

**Goals:**

- Use the first enabled `setting_sale_locations` entry in resolver order as the sole ownership source for non-stock POS parents and non-stock bundle components.
- Preserve stock-managed allocation, source ownership, serial controls, tax-bucket behavior, stock deduction, and transaction posting.
- Persist approved audit-only non-stock DispatchDetails during atomic POS checkout posting and retain every generated Sale as `DISPATCHED`.
- Keep existing owner split posting, payment allocation, receipt composition, POS Return linkage, and idempotency behavior coherent.

**Non-Goals:**

- No pending service acknowledgement, work order, technician, repair intake, new Sale status, or separate service lifecycle.
- No inventory mutation, serial reservation/history mutation, stock validation, or stock requirement for a non-stock product.
- No rewriting historical checkouts, Sales, Dispatches, returns, or location configuration.

## Decisions

### Resolve non-stock ownership from the configured POS location order

The split planner SHALL introduce one shared non-stock source resolution path backed by `SalesLocationResolver::resolveLocationIds($terminalSettingId)`. The first returned location is the owner location and that location's `setting_id` is the owner setting. This is the existing enabled `setting_sale_locations` order: `position`, owned-location tie-break, name, then ID.

This replaces both the terminal-setting fallback for standalone non-stock lines and the first-non-PKP bundle-component rule. Filtering by PKP was rejected because configuration order, not tax registration, is the business-selected ownership priority. The resolved owner setting's existing tax policy still determines the split tax bucket.

### Keep stock allocation and non-stock source resolution separate

Only stock-managed parents and components enter `ResolvePosStockAllocationsService`. Their allocations remain the authority for source setting/location, tax bucket, serial controls, and inventory effects. Non-stock content receives a synthetic planning chunk solely for financial ownership and audit dispatch persistence; it is not an allocation and cannot be used by stock movement code.

For a non-stock bundle parent with a stock-managed RAM component, parent residual revenue belongs to the configured first source while RAM component revenue and stock effects belong to the normal RAM allocation source. The existing split key (`source_setting_id:source_location_id:tax_bucket`) determines whether that produces one combined Sale or multiple Sales. This prevents double counting.

### Create approved audit-only dispatch details in the existing POS posting transaction

The inline posting adapter SHALL create a DispatchDetail for non-stock content with the resolved group source location, the product/bundle/tax context, and fulfilled quantity. Its Dispatch remains approved at checkout and its Sale remains `DISPATCHED`, matching all other POS-generated Sales.

No service-specific entity is needed. The current Dispatch model provides audit ownership, approval timestamps, POS Return source evidence, and consistency with stock POS lines. The implementation must branch on the persisted product classification before any inventory operation; a crafted cart snapshot cannot turn a stock-managed product into audit-only content.

### Preserve complete checkout mappings rather than top-level first-group shortcuts

`pos_checkout_sales` and `split_summary` remain the canonical one-checkout-to-many-Sales mapping. Receipt and POS Return paths must continue to use those mappings, because the checkout response's top-level sale/dispatch fields represent the first group only. Idempotent replay must return the persisted result and never create another dispatch or DispatchDetail.

## Risks / Trade-offs

- [A configured first source has no stock] → Non-stock posting still uses it; stock-managed content continues to move to later configured sources only through normal allocation.
- [A source-order change changes future non-stock ownership] → resolve and snapshot the source inside checkout finalization; historical split mappings remain immutable.
- [Mixed bundles create Sales under multiple owners] → retain existing split payment allocation and exact minor-unit reconciliation tests.
- [Audit details accidentally enter inventory code] → re-load product classification server-side and assert no ProductStock, Product, serial, or transaction writes for non-stock details.
- [Receipts or returns assume one Sale/Dispatch] → test through `pos_checkout_sales` and split summary, including service-parent/stock-component split cases.

## Migration Plan

Deploy as behavior-only changes with no schema migration. New checkouts record complete non-stock DispatchDetails; historical checkouts retain their existing records. Rollback is code rollback, and already-posted audit details remain valid immutable audit records.

## Open Questions

None. POS checkout is explicitly immediate: every generated Sale and Dispatch is approved/dispatched at checkout.

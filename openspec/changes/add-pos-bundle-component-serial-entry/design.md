## Context

POS serial assignment today is a single mechanism scoped to one `lineId`: `StorePosCartSerialAssignmentRequest` (`Modules/Pos/Http/Requests/StorePosCartSerialAssignmentRequest.php`) accepts `serial_numbers[]` posted to `pos.sell.cart.lines.serials.store` for a given cart line, and the existing serial modal (`pos-serial-modal-gui`) opens against that one line. This works for a serial-tracked bundle *parent* (proven end-to-end by `POSSplitSerialBundleCheckoutTest`) but has no dimension for a bundle *component* — a `ProductBundleItem`-derived sub-line has no `lineId` of its own to assign against.

Normal-Sales dispatch already solved the equivalent problem using a composite `product_id-tax_id-bundle_id` aggregation key that treats each bundle component as its own addressable unit (`SaleController.php` `dispatch()`/`storeDispatch()`), backed by `dispatch_details.serial_numbers` (JSON) plus a `bundle_id` tag column. POS split posting (`PosCheckoutSplitPlannerService`) already partitions bundle component stock across owners using bundle-aware grouping, so the concept of "a component within a bundle line" already exists in POS's checkout-time model — it's just never been exposed for serial assignment specifically.

## Goals / Non-Goals

**Goals:**
- Let a cashier open a bundle cart line and assign serials to each serial-required component independently, via a bundle-detail modal.
- Reuse the existing scan-input behavior (dedup, Enter-suppression) so scanning workflow is unchanged from the cashier's perspective, just re-targeted per component.
- Persist partial component-serial entry on saved POS drafts.
- Block checkout on a bundle row until the parent (if serial-required) and every serial-required component have serial counts matching their required quantities.
- Carry per-component serial assignments through split posting so each owner-partitioned Sales document receives the correct component serials.

**Non-Goals:**
- No changes to normal-Sales dispatch application code (already correct; only a regression test is added).
- No schema change to `dispatch_details` — the existing `product_id`/`bundle_id`/`serial_numbers` columns already support component-level serial storage once POS starts writing to them per-component.
- No change to bundle definition, pricing, activation/lifecycle, or non-serial checkout flows.
- No support for reassigning/moving a serial after it's been assigned to a component mid-draft (out of scope; matches existing parent-line serial behavior, which also doesn't support reassignment).

## Decisions

**1. Component identity key: `bundle_item_id` (or `product_id` scoped within the bundle line), not a synthetic new `lineId`.**
Alternative considered: give every bundle component its own top-level cart `lineId`, treating it like a standalone line. Rejected — this would require restructuring cart-line aggregation and pricing/allocation logic (Sequence 3's settled invariants), which is out of scope and risks regressing the parent/component pricing split. Instead, extend the existing bundle cart-line JSON with a nested per-component serial map, addressed by the component's `ProductBundleItem` identity — mirroring how normal-Sales dispatch already addresses components via `product_id-tax_id-bundle_id` rather than inventing a parallel line concept.

**2. Request/route shape: extend `StorePosCartSerialAssignmentRequest` with an optional `component_id` (or `bundle_item_id`), not a new endpoint.**
Alternative considered: a separate `pos.sell.cart.lines.bundle-components.serials.store` route. Rejected as unnecessary duplication — the append/remove semantics (append one serial, clear-and-refocus, remove-by-chip) are identical to the parent case; only the target changes. Add the field as optional so the parent-line contract is unchanged for non-bundle and non-serial-component bundle lines (backward compatible).

**3. Modal UX: new bundle-detail modal that lists components, each with its own scan target field, built on top of the existing scan-input component.**
The existing `pos-serial-modal-gui` modal assumes one product context per open. The bundle-detail modal instead shows N component rows; clicking/focusing a component row makes it the "active" scan target, and the shared scan-input component's Enter/append behavior is redirected to whichever component is active. Auto-advance: once a component's assigned-serial count reaches its required quantity, focus moves to the next incomplete component automatically, so continuous scanning doesn't require manual re-targeting between components in the common case.

**4. Draft persistence: nested serial map inside the existing bundle cart-line JSON payload.**
Alternative considered: a dedicated draft-serial table keyed by draft+bundle+component (mirroring `dispatch_details`). Rejected per explicit preference — no new table; drafts already round-trip a JSON cart-line structure, so the component serial map travels with it naturally and requires no new persistence layer or migration.

**5. Checkout gating: reuse `pos-checkout-serial-stock-validation`'s fulfillment-check pattern, extended to iterate bundle components.**
The existing check already validates "assigned serial context" per line for parent-line serial requirements. Extend the same check to also iterate each bundle line's components and treat any `serial_number_required` component with `assigned_count < required_qty` as unfulfilled, using the same `STOCK_UNAVAILABLE` / unfulfilled-lines diagnostic shape already established, so the failure UX cashiers already understand is unchanged.

**6. Split posting: extend `PosCheckoutSplitPlannerService`'s `assigned_serials` handling to carry per-component serials through partitioning.**
The split planner already partitions bundle component stock across owners using bundle-aware grouping. Extend the structure it reads/writes so that when it assigns a component's stock to a specific owner group, it carries that component's specific assigned serial(s) along, rather than only ever consuming parent-line serials — following the same `product_id-tax_id-bundle_id` composite addressing normal-Sales dispatch and the split planner's existing bundle grouping already use.

## Risks / Trade-offs

- **[Risk]** Extending shared request/service structures (`StorePosCartSerialAssignmentRequest`, split planner `assigned_serials`) could regress the already-working parent-line serial path if the component dimension isn't strictly additive. → **Mitigation**: keep `component_id`/`bundle_item_id` optional everywhere, default to current parent-only behavior when absent, and run the existing `POSSplitSerialBundleCheckoutTest` / `POSReturnBundleRegressionTest` suites (touched-file scope) after each change to confirm no regression.
- **[Risk]** Auto-advance focus logic in the modal could misbehave with fast/queued scanner input if a component reaches its required quantity mid-buffer. → **Mitigation**: reuse the existing scan-input component's proven dedup/Enter-suppression behavior rather than writing new input-handling logic; only the *target* changes, not the input mechanism.
- **[Risk]** Draft JSON growing more complex (nested per-component serial maps) could make manual draft inspection/debugging harder. → **Mitigation**: keep the nested structure keyed simply by `bundle_item_id => [serials]`, consistent with how `dispatch_details.serial_numbers` already stores a flat serial array.

## Migration Plan

No database migration required. Deploy is a standard application-layer release:
1. Ship backend changes (request, split planner, validation) behind the additive/optional field — safe to deploy ahead of frontend.
2. Ship the bundle-detail modal and cart UI changes.
3. No data backfill needed; existing drafts without component serial data simply have no entries in the new nested map, and are treated as "no components require serials" only if none of their components are actually `serial_number_required` — if a live draft's bundle happens to include a serial-required component from before this ships, checkout gating will correctly require serial entry going forward.

Rollback: revert the application release; no schema to roll back.

## Open Questions

- Exact field/route naming (`component_id` vs `bundle_item_id`) — resolve during implementation by matching whatever identifier `ProductBundleItem` already exposes to the cart-line payload.
- Whether the bundle-detail modal should show non-serial-required components (informational, no input) or only serial-required ones — default to showing all components for context, with input fields only on serial-required rows, unless implementation surfaces a UX reason to hide non-serial rows.

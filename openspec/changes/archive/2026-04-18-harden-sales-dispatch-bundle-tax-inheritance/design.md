## Context

Sales dispatch builds composite keys (`product_id-tax_id-bundle_id`) to drive quantity aggregation, location stock display, and server-side validation. For direct sale lines, `tax_id` is available from `sale_details`, but for bundle components dispatch currently reads tax context from `sale_bundle_items`, which does not persist `tax_id`.

This creates inconsistent behavior for non-serial bundle components: taxed parent lines can be treated as non-tax during dispatch stock checks, causing incorrect available-stock display and validation outcomes.

## Goals / Non-Goals

**Goals:**
- Make bundle-component dispatch tax context deterministic by inheriting from the parent `sale_details.tax_id`.
- Keep UI stock display and backend validation aligned on the same inherited tax context.
- Preserve existing tax-bucket segregation behavior (`quantity_tax` vs `quantity_non_tax`) while eliminating ambiguity for bundle rows.
- Add regression tests for taxed and non-taxed parent bundle dispatch cases.

**Non-Goals:**
- Redesigning the overall stock model or removing tax-bucket separation.
- Changing POS checkout tax-resolution behavior.
- Introducing external API contract changes.

## Decisions

1. Derive bundle dispatch tax context from parent sale detail.
- Decision: Resolve bundle line tax via `sale_bundle_items.sale_detail_id -> sale_details.tax_id` and use that value in composite keys.
- Rationale: Parent sale line tax already represents transaction intent and is persisted reliably.
- Alternative considered: Add `tax_id` to `sale_bundle_items` and backfill. Rejected for this hardening pass due to migration complexity and because parent linkage already provides canonical tax context.

2. Keep bucket selection logic unchanged; fix key inputs instead.
- Decision: Continue selecting stock with `tax_id > 0 => quantity_tax`, else `quantity_non_tax`.
- Rationale: Existing behavior is intentional and tested; the issue is incorrect tax context entering the lookup.
- Alternative considered: Fallback to total quantity for bundle rows. Rejected because it would bypass strict tax segregation.

3. Align both read paths used by dispatch flow.
- Decision: Apply inherited tax context in both dispatch-page aggregation and store/validation aggregation.
- Rationale: Prevents UI/validation drift where displayed stock and final validation diverge.
- Alternative considered: Fix only validation path. Rejected because operators still see misleading stock values before submit.

## Risks / Trade-offs

- [Risk] Parent sale detail missing or orphaned relation for a bundle item could break tax resolution.
  → Mitigation: Guard null parent references and treat as validation error for affected row; add test coverage for expected relation integrity.

- [Risk] Historical sales with inconsistent bundle linkage may surface previously hidden data quality issues.
  → Mitigation: Scope change to dispatch-time resolution and fail safely with actionable validation messaging.

- [Trade-off] Tax context remains runtime-derived rather than denormalized into `sale_bundle_items`.
  → Mitigation: Keep implementation minimal now; evaluate denormalization in a separate migration-focused change if needed.

## Migration Plan

- No schema migration required for this hardening change.
- Deploy application code and test updates together.
- Rollback strategy: revert controller/Livewire resolution changes to previous behavior if unexpected production regressions occur.

## Open Questions

- Should future work add a persisted `tax_id` on `sale_bundle_items` for historical reporting ergonomics, while still enforcing inheritance at write-time?

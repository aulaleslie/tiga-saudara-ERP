## Context

POS checkout currently posts parent sale lines and dispatch details, but bundle component persistence is stock/dispatch-only and not reflected in `sale_bundle_items`. Sales already supports standalone bundle rows and uses them in invoice/detail rendering and return eligibility fallback resolution. The current gap is cross-module: POS write-path omits rows that Sales read-path expects for deterministic context.

Constraints:
- POS supports inline and split posting, and both must produce equivalent Sales-domain artifacts.
- Existing non-bundle and linked bundle behavior must remain backward compatible.
- Mixed-source ownership and tax bucket resolution already occur during allocation; persistence must reuse this resolved context rather than recalculate from scratch.

Stakeholders:
- POS checkout reliability and reconciliation.
- Sales detail/invoice/return workflows that rely on persisted bundle context.
- Support/debug teams requiring deterministic traceability from checkout line to persisted bundle components.

## Goals / Non-Goals

**Goals:**
- Persist bundle component rows during POS finalize so downstream Sales paths can resolve bundle context deterministically.
- Define one persistence contract shared by inline and split posting.
- Preserve compatibility with existing Sales linked-row behavior and standalone fallback behavior.
- Add tests that prove bundle-only and mixed-cart scenarios retain return/invoice/read consistency.

**Non-Goals:**
- Reworking POS cart UI bundle authoring semantics.
- Changing Sales schema again (current standalone-ready schema is sufficient).
- Refactoring all Sales reporting surfaces beyond behaviors directly affected by missing bundle rows.

## Decisions

1. Persist bundle component rows in POS posting adapter at checkout posting time.

- Decision: extend `InlinePosCheckoutPostingAdapter` to create `sale_bundle_items` rows from each cart line's `bundle_items`, with canonical fields (`sale_id`, `sale_detail_id`, `bundle_id`, `bundle_item_id` when available, `product_id`, `name`, `quantity`, `price`, `sub_total`, `tax_id`, `tax_amount`, `line_group_key`).
- Rationale: posting adapter already owns sale/dispatch stock mutation and has final allocation context; this is the narrowest place to keep write operations atomic.
- Alternative considered: post-process job after checkout finalize. Rejected because eventual consistency can break return/read behavior between checkout completion and job completion.

2. Keep parent-linked bundle persistence as default for current POS bundle lines, but write standalone-compatible fields unconditionally.

- Decision: when a parent `sale_details` row exists, persist `sale_detail_id`; always fill `tax_id`, `tax_amount`, and deterministic `line_group_key` so the same rows remain valid if parent linkage is missing in future flows.
- Rationale: supports immediate compatibility while preparing future bundle-only sales without schema or contract churn.
- Alternative considered: force `sale_detail_id = null` immediately for POS bundle rows. Rejected because it introduces a behavioral jump in current assumptions and broader regression risk.

3. Reuse split-planner chunk context for tax and grouping values.

- Decision: compute row tax context from chunk-level effective tax snapshot already produced by allocation/split planning; do not derive tax independently from product defaults.
- Rationale: ensures persisted rows match the same source-setting/tax-bucket decisions used for dispatch and stock deductions.
- Alternative considered: derive tax from line `tax_id` or setting defaults. Rejected because mixed-source checkout can diverge from terminal defaults.

4. Add explicit POS↔Sales contract tests.

- Decision: introduce feature coverage asserting `sale_bundle_items` rows exist after finalize and are usable by Sales return eligibility and sales document projections.
- Rationale: previous tests validated stock and dispatch behavior but not bundle-row persistence contract.
- Alternative considered: rely on isolated Sales standalone tests. Rejected because they do not verify POS write-path behavior.

## Risks / Trade-offs

- [Duplicate bundle row writes on idempotent replay] → Mitigation: gate row creation behind idempotency state and verify replay returns existing artifacts without additional inserts.
- [Tax mismatch between parent line and component rows] → Mitigation: use planner/allocation snapshot as source of truth and assert reconciliation in tests.
- [Increased row volume in large POS sessions] → Mitigation: batch insert per posted sale where feasible and keep payload minimal.
- [Ambiguity of `line_group_key` format across modules] → Mitigation: define one deterministic key format tied to checkout line + bundle component index and reuse it in tests/logs.

## Migration Plan

1. Implement adapter-level bundle-row persistence in inline posting path.
2. Validate split posting path automatically inherits behavior via inline adapter usage per group.
3. Add/adjust feature tests for single-source and split multi-source bundle checkout persistence.
4. Add integration assertions for Sales return eligibility and sales document/read projections for POS-created rows.
5. Rollback strategy: disable POS bundle-row persistence branch via guarded code path while leaving schema untouched; existing stock/dispatch behavior remains functional.

## Open Questions

- Should POS persist bundle component pricing exactly as item snapshot price, or prorate from parent-discounted totals when discounts are applied at parent line?
- For multi-quantity bundle lines, should `line_group_key` represent each component aggregate row or preserve per-parent-line multiplicity for auditing?
- Do we want an explicit source marker (e.g., `note`/metadata) for POS-originated standalone-compatible rows, or is existing sale note/reference sufficient?

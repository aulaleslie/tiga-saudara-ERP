## Context

POS bundle composition shown in transaction detail and printed/reprint receipt is reconstructed from completed checkout split sales. Current reconstruction selects and consumes a single matching composition group per bundled POS line. In split ownership scenarios, one bundled parent line can have component rows distributed across multiple split sales, including component-only groups where `sale_details.quantity = 0`. Selecting only one group can drop valid components.

The rendering contract is customer-facing: show component name + quantity only, without owner/allocation/price internals. Existing behavior that prevents non-bundled lines from inheriting components must be preserved.

## Goals / Non-Goals

**Goals:**
- Reconstruct complete component composition for one bundled POS line across all relevant split sales groups.
- Include component-only split groups (`parent_qty = 0`) when they belong to the same bundled parent line.
- Preserve line isolation so bundled components are not leaked into non-bundled rows or unrelated rows.
- Keep receipt and transaction detail output format unchanged (name + qty only).
- Add regression tests for 2-owner and 3-owner split ownership and mixed bundled/non-bundled parent rows.

**Non-Goals:**
- Changing split posting allocation logic, tax logic, or ownership priority.
- Changing database schema for checkout/sales/transaction tables.
- Exposing owner/source metadata in customer-facing composition rows.

## Decisions

1. Replace single-group consumption with line-level aggregation
- Decision: For each bundled POS line, gather all composition groups that belong to that line and aggregate component quantities by component identity.
- Rationale: A split bundle can emit multiple sale details for one parent line; single-match semantics are lossy.
- Alternative considered: Keep single-match and fallback to line metadata only.
  - Rejected because completed checkout should be sourced from persisted split sales truth, and fallback-only cannot reliably reconcile cross-owner quantities.

2. Keep consume-once semantics at parent-line granularity
- Decision: Continue consuming persisted groups so they cannot be reused by subsequent lines, but consume all groups associated with the resolved parent line instead of exactly one.
- Rationale: Prevents component leakage into another row while allowing full reconstruction for the intended row.
- Alternative considered: Global merge by product id and qty without consumption.
  - Rejected because mixed rows with same parent product (bundled + plain) would over-attach components.

3. Preserve strict bundle-line gate
- Decision: Keep existing gate that only bundled transaction lines are eligible for composition mapping.
- Rationale: Prevents accidental display changes for standard non-bundled rows.
- Alternative considered: Infer bundle relevance from sale details only.
  - Rejected due high risk of false-positive mapping when product appears in multiple row types.

4. Maintain `line_meta` fallback only for empty reconstruction
- Decision: Keep fallback behavior, but only after aggregated split-group resolution returns empty.
- Rationale: Supports draft/edge cases while prioritizing completed checkout persisted data.
- Alternative considered: merge fallback with reconstructed groups.
  - Rejected to avoid duplication and contradictory totals when both sources exist.

## Risks / Trade-offs

- [Risk] Over-grouping could merge components from separate bundled rows of the same parent product.
  → Mitigation: consume-at-line resolution and add mixed-line regression coverage.

- [Risk] Under-grouping could still miss component-only (`parent_qty=0`) groups.
  → Mitigation: explicitly include component-only groups during line aggregation and verify with 3-owner test fixture.

- [Risk] Receipt/detail UI regressions due changed composition ordering.
  → Mitigation: keep stable deterministic output ordering and update assertions to focus on completeness + no duplication.

- [Trade-off] More reconstruction logic complexity in receipt service.
  → Mitigation: isolate aggregation in helper methods and maintain focused tests around resolver behavior.

## Migration Plan

1. Update composition resolver logic in `PosReceiptService` to aggregate all relevant groups per bundled line.
2. Add/adjust POS feature tests for:
- split bundle 2-owner completeness,
- split bundle 3-owner completeness,
- mixed bundled/non-bundled same parent line isolation.
3. Run focused POS split/receipt/detail tests.
4. Rollback strategy: revert resolver changes and test updates; no data migration required.

## Open Questions

- Should component ordering follow persisted sale detail order or canonical product/name sort for deterministic rendering?
- For rare malformed historical records where grouping is ambiguous, should resolver prefer strict no-display or best-effort display with fallback metadata?

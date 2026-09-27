## Context

Sales currently stores bundle composition in `sale_bundle_items` with a required `sale_detail_id` foreign key. This works for standard Sales create/update, but it couples downstream Sales behavior (dispatch tax context, return eligibility context, detail rendering) to parent line inheritance only.

The intended future direction requires Sales to tolerate bundle rows that may not have a parent `sale_details` row, while preserving existing behavior for standard Sales flows. This is a cross-cutting Sales-domain change touching data shape, dispatch/read logic, and reporting/document semantics.

## Goals / Non-Goals

**Goals:**
- Prepare Sales domain contracts so bundle rows can be represented with or without a parent `sale_details` row.
- Preserve current standard Sales write behavior in this phase (linked parent + bundle rows).
- Define deterministic fallback rules for dispatch/read paths when `sale_detail_id` is absent.
- Keep backward-compatible behavior for existing linked sales records.

**Non-Goals:**
- Implementing POS posting changes in this change.
- Changing standard Sales UI create/edit behavior to produce standalone bundle rows.
- Reworking all reporting formulas in this phase beyond explicit contract updates.
- Backfilling historical data unless explicitly required by schema constraints.

## Decisions

1. Use a hybrid data contract for bundle rows.

   `sale_bundle_items.sale_detail_id` becomes optional at model/contract level, but standard Sales continues writing linked rows in this phase.

   Rationale: this gives forward compatibility for future ownership-based posting while minimizing immediate regression risk.

   Alternative considered: fully decoupled model immediately. Rejected due to high blast radius across dispatch, returns, and document/report logic.

2. Add standalone-ready self context on bundle rows.

   Bundle rows will carry self context required for fallback behavior (for example: deterministic line grouping key and explicit tax/source context fields).

   Rationale: fallback logic cannot rely on parent inheritance if parent is absent.

   Alternative considered: derive all fallback context from dispatch details only. Rejected because it leaves Sales detail/read paths without stable source context.

3. Define dual-resolution rule in Sales read paths.

   - Primary path: if `sale_detail_id` exists, use parent-inherited context.
   - Fallback path: if `sale_detail_id` is null, use bundle-row self context.

   Rationale: preserves existing semantics while making standalone rows first-class.

4. Keep current standard Sales persistence behavior unchanged in this phase.

   Standard Sales create/update remains linked parent+bundle writes.

   Rationale: separates preparation from behavior change and avoids user-facing change before POS follow-up.

## Risks / Trade-offs

- [Dispatch or return logic accidentally assumes parent always exists] → Mitigation: require explicit fallback scenarios in specs and targeted regressions.
- [Document/report totals become ambiguous for standalone bundle rows] → Mitigation: define visibility/accounting policy in spec scenarios before implementation.
- [Schema looseness allows invalid orphan rows] → Mitigation: require minimal self-context fields and validation for rows with null `sale_detail_id`.
- [Mixed linked/standalone records complicate debugging] → Mitigation: add deterministic `line_group_key` (or equivalent) and use it in diagnostics/logging/test fixtures.

## Migration Plan

1. Introduce schema support for optional parent linkage and standalone self-context fields.
2. Update model/service contracts to tolerate null `sale_detail_id` without changing standard Sales write behavior.
3. Update dispatch/read/return paths to use dual-resolution logic (parent first, self-context fallback).
4. Keep default standard Sales output linked-only for this phase.
5. Rollback strategy: preserve backward compatibility by continuing to support linked path; if issues emerge, disable standalone fallback path while keeping schema additions inert.

## Open Questions

- Should standalone bundle rows be billable lines in invoice/total calculations or operational-only lines?
- Should invoice/detail UI render standalone rows in a dedicated section or merge with standard lines?
- For return eligibility, is `dispatch_detail_id` sufficient linkage or should `line_group_key` be required for consistency checks?
- Which reporting surfaces must include standalone bundle rows in revenue metrics versus operational metrics?

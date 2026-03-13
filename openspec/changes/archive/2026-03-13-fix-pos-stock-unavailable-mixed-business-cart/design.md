## Context

Issue B shows a false-negative stock failure in POS finalize: serial-required lines are pre-checked using only `product_id`, `qty`, and `tax_id`, while line-level `tax_id` can be null even when assigned serial stock is taxable and valid in an allowed location. This causes checkout to fail before posting with a generic `STOCK_UNAVAILABLE` error.

Current constraints:
- Non-serial stock behavior is already used in production and should remain stable.
- Serial posting paths already rely on assigned serial records, so pre-check must align with that contract.
- Mixed-business checkout may be split-posted; tax-bucket derivation must stay deterministic.

## Goals / Non-Goals

**Goals:**
- Make finalize stock pre-check serial-aware so valid assigned-serial lines are fulfilled correctly.
- Preserve existing non-serial allocation semantics.
- Return actionable failure diagnostics for `STOCK_UNAVAILABLE`.
- Align split tax-bucket planning with serial tax evidence when line-level tax is absent.
- Add regression tests for mixed serial/non-serial checkout coverage.

**Non-Goals:**
- Redesigning inventory data models or introducing new stock tables.
- Reworking POS UI flows in this change.
- Forcing runtime enablement of split posting in all environments.

## Decisions

1. Extend resolver line input with optional serial metadata.
Rationale: finalize currently strips context that is needed to classify serial lines correctly. The resolver input will include serial-specific fields (for example: serial-required flag and assigned serial identifiers) while keeping legacy fields for non-serial lines.
Alternative considered: infer serial context only inside finalize and bypass resolver for serial lines. Rejected because it duplicates fulfillment logic and weakens a single stock-precheck contract.

2. Add a serial-aware fulfillment path in `ResolvePosStockAllocationsService`.
Rationale: serial lines should be validated against assigned serial records (active status, allowed location, and effective tax) instead of defaulting to non-tax quantity buckets when `line.tax_id` is null.
Alternative considered: treat null tax serial lines as taxable by default without serial verification. Rejected because it can pass invalid or stale serial assignments.

3. Add structured stock failure diagnostics while preserving current failure code.
Rationale: existing `STOCK_UNAVAILABLE` message is not enough for triage. Include unfulfilled line details (index, product, and reason) in failure payload/log metadata, while retaining `failure_code=STOCK_UNAVAILABLE` for compatibility.
Alternative considered: logging only. Rejected because API responses also need actionable context for operators and QA.

4. Update split tax-bucket resolution order for serial-assigned lines.
Rationale: split planner currently classifies taxable/non-tax based mainly on line tax flags, which can misclassify serial lines with null `tax_id`. Planner should use serial-derived tax context for serial lines before fallback policies.
Alternative considered: no split-planner change while split posting is feature-flagged. Rejected because enabling split posting later would reintroduce incorrect bucketing.

5. Regression-first validation strategy.
Rationale: issue manifests only under mixed conditions (serial+non-serial, mixed business/tax), so tests must encode this matrix to prevent recurrence.
Alternative considered: manual verification only. Rejected due high regression risk in finalize path.

## Risks / Trade-offs

- [Additional data lookup for serial validation] -> Batch-fetch assigned serial records per checkout line to avoid per-serial N+1 queries.
- [Behavior differences between pre-check and posting if contracts drift] -> Use one shared interpretation of assigned serial context and enforce it in tests.
- [Larger failure payload/log metadata] -> Keep diagnostics compact and bounded to unfulfilled lines only.
- [Ambiguous serial tax for inconsistent serial data] -> Fail line with explicit reason rather than guessing tax bucket.

## Migration Plan

- Implement resolver/finalize/planner updates behind existing finalize flow, with no schema migration.
- Add feature/behavior tests for mixed-business serial checkout success and actionable stock failure diagnostics.
- Validate split planner behavior under split-posting-enabled test path.
- Deploy as standard service update.
- Rollback strategy: revert this change set to restore prior pre-check behavior if unexpected production issues appear.

## Open Questions

- Should diagnostic line details be returned to all clients or only privileged internal clients while always logging full detail?
- For serial lines with multiple assigned serials of mixed tax statuses, should finalize reject the line or split line quantities by tax bucket in a future enhancement?

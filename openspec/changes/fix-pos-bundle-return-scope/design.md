# Design

## Context

See proposal.md for the incident. The current non-serial snapshot checks the parent's direct SaleBundleItem relationship; it misses components attached to another owner's zero-quantity carrier detail. The carrier is filtered from visible return rows. Submission synthesis and completeness validation can also miss bundle evidence when no POS line/serial link is supplied. Existing specs prohibit parent-only cash return, so this change modifies behavior as well as fixing detection.

Observed source: transaction 7088, checkout 6867, POS line 33194, bundle 36. Camera sale/detail/dispatch-detail: 210538/423830/424501. MicroSD sale/carrier/bundle-item/dispatch-detail: 210539/423831/900/424502. The saved return 111 contains only camera return line 153 and Sale Return Detail 138.

## Goals / Non-Goals

Goals: one durable scope contract across draft, preview, and execution; source-bound composition; owner-aligned physical and monetary corrections; an append-only correction audit for the incident.
Non-goals: new individual-component cash-return UI, replacement redesign, checkout rewrite, automatic mass repair, or application/database changes during proposal preparation.

## Decisions

### 1. Persist scope per actionable bundle parent
Add nullable bundle_return_scope to parent return-line persistence with allowed values whole_bundle and parent_only. New bundle cash-return choices default to whole_bundle. A server-side new-request default handles omitted scope safely; explicit invalid values fail validation. UI shows included/retained items and values. Scope does not imply an action for an unselected line. Propagate the selection and parent grouping into execution context and approval evidence.

Alternative: a header-only scope cannot represent different choices for multiple bundle lines. Inferring scope from missing components would hide this incident and is rejected.

Historical null scope remains unknown. Completed history stays immutable. Legacy editable drafts require explicit review; pending bundle drafts without scope must use an authorized return-to-draft/rejection-and-edit path before approval, preserving audit fields. Do not silently reclassify them.

### 2. Resolve original composition before expansion
Extend shared bundle resolution and use it consistently in snapshot, synthesis, completeness checks, and approval planning. Prefer exact POS line/sale-detail links and persisted dispatch bundle identity plus checkout owner mappings. Resolve components through original SaleBundleItem, carrier detail, and DispatchDetail evidence, including zero-quantity carrier rows.

Original POS line metadata supplies captured composition and customer bundle price; current catalog data must not override sold composition. Scope resolution to the exact checkout and bundle instance. For legacy absent links, use available bundle/line-group and dispatch evidence only when unique; block ambiguous repeated products/instances. Do not add a product-only first-match fallback.

Alternative: only fixing the snapshot leaves server submission and approval vulnerable; using today's catalog can reverse products that were not sold.

### 3. Separate physical scope and commercial allocations
Whole bundle expands selected quantity into proportional original components across owner sales. Parent only omits component execution while retaining their eligibility/charges. Existing replacement intent remains independent.

Use original allocated monetary evidence, including taxes and discounts. In the incident, camera allocation is Rp630,000, microSD Rp260,000, customer bundle price Rp890,000. Whole bundle reduces Rp890,000 once; parent only reduces Rp630,000. Refund and debt reduction are separate settlement outputs, not aliases for line value. Preserve credit applications and existing payment safeguards.

Retained component SaleBundleItem amounts must continue contributing to totals even when the parent/carrier quantity is zero. Sale archival and returned-status checks must consider remaining component commercial and dispatch obligations. Preserve owner/location, stock-managed versus stockless behavior, serial identities, and existing HPP/cost correction patterns.

### 4. Validate scope at every boundary
Submission expands only whole-bundle lines, validates parent-only values, and rejects individual component cash intent. Preview displays scope and retained obligations. Final approval rebuilds under existing atomic lifecycle controls and blocks missing/ambiguous identities, overlapping claims, insufficient serial evidence, or stale affected financial evidence. Retry must not repeat execution.

### 5. Correct the historical omission through linked compensating evidence
Provide a narrowly scoped dry-run-first correction command/service following existing POS repair conventions. Require target, explicit apply, actor, reason, and confirmation of physical microSD return; coding the correction does not execute it.

Create a separately linked corrective POS/Sales Return record or equivalent first-class correction entity carrying a stable identity and immutable reference to return 111. Reuse existing receiving, dispatch quantity, commercial, stock-bucket, cost, and settlement services for the omitted component. The correction is an administrative exception to normal component-only rejection; it must never become a general user return option.

Lock and revalidate the completed parent evidence, original component quantity/allocation, active dispatch, stock, and settlement records. Abort on changed or ambiguous evidence. Append audit and correction details; do not rewrite the saved historical snapshot or re-run the camera reversal. Correction identity plus transaction guards provide idempotency.

Alternative: directly setting due_amount to zero leaves stock, dispatch, commercial totals, and cost evidence wrong. Reopening/replaying the original return risks duplicate camera effects.

## Risks / Trade-offs

- Ambiguous historical linkage → block with actionable evidence instead of guessing.
- Changed catalog composition → original capture and persisted allocation remain authoritative.
- Retained components hidden behind zero-quantity carrier → include them in totals, debt, detail display, and archival eligibility.
- Older pending drafts conflict with new intent → explicit reviewed scope required before execution.
- Correction could duplicate a manual repair → check existing receiving, inventory, return, and settlement evidence under locks.
- Additional resolution queries → eager-load checkout composition and keep lookup performance regression coverage.

## Migration Plan

1. Add scope persistence without mass backfilling historical intent; deploy form and server validation together.
2. Verify the incident-shaped fixture and existing serial, same-owner, replacement, settlement, and repeated-return tests.
3. Run correction dry-run against the local dataset, compare exact source IDs and expected effects, and retain the report.
4. Apply only through the explicit correction operation with actor, reason, and physical-return confirmation; verify zero incident debt and exactly one component reversal.
5. Rollback application deployment without deleting scope/audit data. Any executed correction requires an audited compensating operation, never deletion of history.

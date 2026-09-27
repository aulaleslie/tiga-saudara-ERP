## Context

The POS Return workflow is intended to wrap owner/sale-aligned Sales Return execution. Draft creation and submit-for-approval are intentionally intake-only and must not create Sales Returns or mutate stock, serials, dispatches, payments, or source sales.

Current approval is too abrupt for the risk profile. The approver sees a POS Return detail page, clicks approve, and the backend can move the POS Return lifecycle without first exposing whether the execution graph is complete. Recent investigation showed important failure modes:

- A pending POS Return can have zero linked Sales Returns.
- Saved POS Return lines can miss `dispatch_detail_id` even when the source serials have dispatch details.
- Repeated same-SKU serial dispatches can be misidentified if resolved only by `sale_id + product_id`.
- Mixed line resolutions can be ambiguous when the header has a single `return_option`.
- Bundle parent and component stock movements are not always obvious from the visible POS line.

This change introduces a preview-only checkpoint before approval execution.

## Goals / Non-Goals

**Goals:**
- Make the first approve click route to a read-only approval preview page.
- Ensure no web-accessible direct final approval mutation remains available during this preview-only change.
- Generate a deterministic approval execution plan from the pending POS Return and current source data.
- Show the generated Sales Return target shape before any mutation:
  - source POS transaction and checkout context
  - split sale / owner groups
  - planned Sales Return headers
  - planned Sales Return details
  - line-to-target linkage
  - dispatch detail anchors
  - source setting, source location, and tax context
  - returned serial and replacement serial identities
  - stock movement direction and quantity
  - serial movement direction
  - cash-return amount or replacement-dispatch intent
  - bundle/component trace where available
- Mark the preview as blocked when any required execution identity cannot be resolved.
- Keep preview rendering side-effect free.

**Non-Goals:**
- Do not implement the final confirm-approval submission.
- Do not preserve direct web approval as a fallback approval path.
- Do not create or update `sale_returns` or `sale_return_details`.
- Do not mutate POS Return status, approval status, stock, serials, dispatch quantities, payments, source sale status, or lifecycle audit fields from preview rendering.
- Do not solve full line-level mixed resolution execution in this change.
- Do not implement bundle/component execution changes beyond previewing and flagging what is unresolved.

## Decisions

### Decision 1: Approve click becomes preview navigation

The visible pending-approval approve action should route to a `GET` approval preview page. It must not call `PosReturnLifecycleService::approve` directly.

Any existing direct approval `POST` endpoint that remains registered for route compatibility must reject the request with a clear preview-only lifecycle message and must not call lifecycle approval. It must leave POS Return lifecycle fields, linked Sales Returns, stock, serials, dispatches, payments, source sales, and audit fields unchanged.

Rationale: This creates a safety interstitial without changing the final approval execution yet.

Alternative considered: keep the current approve modal and add more warning text. Rejected because the approver needs generated execution data, not only static explanation.

### Decision 2: Add a side-effect-free approval planner with explicit source-of-truth rules

Introduce a planner service that accepts a pending `PosReturn` and returns a structured preview payload. The planner must only read data.

The persisted `pos_return_lines` are the source of operator intent: selected line, quantity, resolution, returned serial, replacement serial, expected cash amount, and captured source IDs. The planner then verifies that intent against current live source state: POS checkout sale, generated sale, sale detail, dispatch detail, returned serial, replacement serial, owner/source setting, source location, tax, product, and bundle trace.

This means a pending POS Return with zero linked `sale_returns` is not automatically blocked. It is expected in the current intake-only workflow if the planner can derive a complete target plan from POS Return lines and live source data.

Suggested shape:

```text
approval_preview
  status: ready | blocked
  blockers[]
  warnings[]
  info[]
  pos_return
  source_transaction
  target_sale_returns[]
    source_sale
    source_owner
    planned_header
    planned_details[]
      pos_return_line_id
      resolution
      sale_detail_id
      dispatch_detail_id
      product
      quantity
      amount
      source_setting_id
      source_location_id
      tax_id
      stock_effect
      serial_effect
      replacement_effect
      bundle_trace
```

Rationale: A structured planner can later be reused by the final approval submission to avoid preview/execution drift.

Alternative considered: build the preview directly in the controller/view. Rejected because the mapping rules are domain logic and need focused tests.

### Decision 3: Preview blockers, warnings, and info are separate

The planner should collect blockers rather than throwing on the first problem unless the POS Return cannot be loaded or is not in a previewable lifecycle state.

Blockers should include:
- no actionable lines
- mixed line resolutions when final execution cannot yet support mixed handling
- stale source snapshot hash or live source state mismatch affecting execution identity
- missing source sale
- missing source owner mapping
- missing dispatch detail for stock-managed lines
- serial line whose returned serial does not belong to the resolved dispatch detail
- replacement line missing replacement serial
- replacement serial not active, wrong SKU, already selected elsewhere, or outside source location rules
- bundle line with unresolved component mapping needed for execution preview

Warnings or info should include:
- no existing linked Sales Returns when the planned targets can still be derived
- header `return_option` that differs from line-level effective resolutions
- non-blocking missing display-only labels such as optional customer or location names

Rationale: Approvers and implementers need a complete list of approval-blocking problems without confusing expected intake-only state for execution failure. Snapshot or live source drift may still be rendered for diagnosis, but it must make the preview blocked when execution identity or eligibility changed.

Alternative considered: fail with a generic message. Rejected because this page exists to make hidden execution risks visible.

### Decision 4: Resolve dispatch identity with serial-first and unique non-serial fallback rules

For serial-tracked lines, the planner should prefer the actual returned serial's `product_serial_numbers.dispatch_detail_id` and then verify it belongs to the expected sale/product context. It should not rely only on the first dispatch detail matching `sale_id + product_id`.

Resolution order:

```text
serial-tracked line
  returned_serial.dispatch_detail_id
  -> verify dispatch belongs to expected sale/product/source context
  -> otherwise blocker

non-serial stock-managed line
  pos_return_line.dispatch_detail_id
  -> sale_detail.dispatch_detail_id when present
  -> dispatch_details.sale_detail_id unique match
  -> sale_id + product_id only when exactly one approved dispatch detail exists
  -> otherwise blocker
```

Rationale: Repeated same-SKU serial sales can produce multiple dispatch details under the same sale and product. Serial identity is the strongest available anchor.

Alternative considered: continue using `sale_id + product_id`. Rejected because it can mis-map serial movement.

### Decision 5: Line-level resolution is authoritative for preview

The planner should derive effective return behavior from actionable POS Return line `resolution` values. The POS Return header `return_option` is legacy/context metadata during preview. A header mismatch should be shown as a warning unless it creates execution ambiguity.

Because final line-level mixed approval execution is not implemented in this preview-only change, a pending return containing both `cash_return` and `product_replacement` actionable lines should be blocked by preview with line-level detail.

Rationale: recent draft changes made per-line resolution the true user intent. Reusing the header as the execution source would reintroduce the hidden ambiguity this preview is meant to expose.

### Decision 6: Preview page is not a submit page in this change

The preview page should make clear that it is a preview. It should provide navigation back to the POS Return detail and display blockers/target plan. It should not expose a final approve/confirm button until a later change implements execution.

Rationale: The user asked for preview only. Keeping final approval out reduces blast radius and lets the team validate mapping before mutation.

Alternative considered: add preview and final approval together. Rejected because that combines planning, UI, lifecycle mutation, Sales Return generation, and stock/serial correctness in one risky change.

## Risks / Trade-offs

- Preview can diverge from later execution if a future final approval path rebuilds mapping differently. Mitigation: keep planner output structured and reuse the same planner for later execution.
- Preview may expose many blockers at first. Mitigation: this is desirable for safety; blockers become the backlog for later execution-hardening changes.
- Existing users may expect approve to finish immediately. Mitigation: label the action and page as approval preview/review, and leave final approval for a later explicit action.
- Mixed resolution returns may need a temporary blocked state. Mitigation: show the line-level conflict clearly and avoid mutating ambiguous returns.

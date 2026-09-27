## Context

POS Return approval preview currently builds planned execution groups from actionable `pos_return_lines`. That is sufficient for ordinary lines, but split-owner bundled POS checkouts can create additional Sales documents for bundle component allocations. In the verified return `#2` case, the checkout produced three Sales documents while the persisted return lines only pointed to the parent serialized phone Sale.

Existing behavior keeps zero-quantity split component rows out of the POS Return intake surface and stores bundle composition as trace/context. That is useful for customer-facing return intake, but the approval preview must now make the execution target map explicit enough for approvers to understand every generated Sale document affected by one returned bundled POS item.

The archived approval-preview spec is preview-only: opening preview must not approve, create Sales Returns, mutate stock, update serials, or touch payment state. This change preserves that boundary.

## Goals / Non-Goals

**Goals:**

- Show all planned Sales Return targets for selected POS Return lines, including split-owner bundle component Sales documents.
- Group the execution preview by generated Sale document, source owner, source location, and tax context.
- Keep a trace from every planned target row back to the customer-facing POS item, serial, and selected resolution.
- Allow mixed `cash_return` and `product_replacement` resolutions in the same POS Return preview.
- Keep preview planning read-only and deterministic from persisted return intent plus current source checkout/Sales data.

**Non-Goals:**

- Do not implement final POS Return approval execution.
- Do not create or mutate `sale_returns`, `sale_return_details`, stock, serial, dispatch, payment, or approval audit records.
- Do not redesign POS Return intake controls in this change.
- Do not backfill historical POS Return lines.

## Decisions

### Decision 1: Derive component target rows during preview planning

The preview planner will continue treating persisted actionable POS Return lines as the selected intent, then derive additional component target rows from the source checkout and generated Sales data. For bundled return lines, the planner should inspect the source POS transaction line/bundle context and match component allocations to generated `sale_bundle_items` rows and their owning `sale_id`.

This keeps intake unchanged while making approval preview reflect the real split-owner Sales graph.

Alternatives considered:

- Persist component allocation rows as first-class `pos_return_lines` immediately. This is likely needed before final approval execution, but it is larger than a preview-only change and risks changing draft/edit semantics.
- Show component Sales documents as context only. That improves visibility but does not satisfy the need to show planned Sales Return targets explicitly.

### Decision 2: Use generated Sale document as the primary preview grouping

The preview should group by planned execution target: source Sale, owner setting, source location, and tax context. Each planned detail row should include its origin: parent POS item, serial when present, component marker, and selected line resolution.

This matches the eventual Sales Return execution boundary while preserving customer-facing traceability.

Alternatives considered:

- Group by POS item first and nest Sale impacts below it. This is useful for readability, but it hides the owner/sale-aligned execution shape approvers need to validate.

### Decision 3: Mixed resolutions are valid at preview time

The planner should remove the global mixed-resolution blocker. It should validate each planned row according to its own resolution:

- `cash_return` rows contribute to cash-return preview totals.
- `product_replacement` parent rows require the selected replacement serial when the returned product is serial-tracked.
- Component rows inherit the parent selected resolution for preview traceability, while stock/serial movement intent remains based on the component's own stock behavior and dispatch context.

This supports realistic returns where one item is refunded and another is replaced.

Alternatives considered:

- Continue blocking mixed resolutions until final approval execution exists. That keeps implementation simpler but prevents the preview from representing accepted business intent.

### Decision 4: Treat unresolved component allocation mapping as a blocker

When an actionable bundled line implies component-owned Sales targets but the planner cannot safely map those components to source `sale_bundle_items`, generated Sale, owner/location, tax context, or dispatch context where required, the preview should show a blocker instead of silently omitting the component target.

This prevents approvers from seeing an incomplete execution map.

## Risks / Trade-offs

- Component matching may be ambiguous when bundle rows lack stable POS line context → Prefer deterministic keys such as `line_group_key`, `sale_id`, product id, and source checkout sale context; report blockers when the mapping is not unique.
- Preview-derived component rows may not exactly match future final approval execution → Keep the data shape explicit and test it so final execution can reuse or promote the same planning model.
- Mixed resolutions increase header summary ambiguity → Make line-level resolution authoritative in preview and show totals split by resolution instead of relying on `pos_returns.return_option`.
- Additional reads may increase preview cost on large receipts → Eager-load source Sales, sale details, bundle rows, checkout sales, settings, locations, taxes, dispatch details, and serials in bounded queries by checkout/return ids.

## Migration Plan

No database migration is planned for this preview-only change. Deployment can ship as ordinary application code and Blade/test updates.

Rollback is code-only: revert the planner/view changes and the preview returns to parent-line-only grouping and mixed-resolution blocking.

## Open Questions

- Should final approval execution later persist component allocation targets as `pos_return_lines`, or create them directly as `sale_return_details` from a shared planner?
- Should component target rows for product replacement create any replacement dispatch requirement, or remain stock/audit-only when the component is not serial-tracked?

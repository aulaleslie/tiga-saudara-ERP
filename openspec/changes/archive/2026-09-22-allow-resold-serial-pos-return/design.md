# Design

## Context

See `proposal.md` for motivation. POS Return lines already persist both `returned_serial_id` and `dispatch_detail_id`, and cumulative return quantity is already accounted per source dispatch. The remaining mismatch is the submission-time serial exclusivity guard, which queries only `returned_serial_id` and therefore treats a reusable serial record as permanently consumed after any completed POS Return.

Sales Return receiving clears the serial's current dispatch association and restores eligible returned stock to an active state. A later sale reuses the serial record and assigns it to a new dispatch detail. Historical POS Return lines retain the earlier dispatch detail, providing the occurrence identity needed without schema changes.

## Goals / Non-Goals

**Goals:**

- Align serial-claim exclusivity with the existing dispatch-scoped quantity model.
- Preserve duplicate protection for concurrent, in-process, and completed returns against one source dispatch.
- Cover direct serialized lines and synthesized serialized bundle-component lines consistently.

**Non-Goals:**

- Changing when a returned serial becomes sellable or how a later sale dispatches it.
- Changing POS Return lifecycle statuses, quantity accounting, execution effects, or serial history.
- Adding migrations, rewriting historical records, or broadening return eligibility outside a genuine later dispatch.
- Running or planning the complete project test suite.

## Decisions

### Use serial plus source dispatch as the claim identity

The exclusivity check will accept the source `dispatch_detail_id` and search historical POS Return lines by both that value and `returned_serial_id`, while retaining the existing active/non-reversed lifecycle filtering and current-return exclusion.

This identity matches the actual entitlement being returned: one serialized unit from one fulfilled dispatch. It also matches existing cumulative quantity accounting, which is dispatch-scoped.

Alternatives considered:

- Serial ID alone is rejected because it prevents legitimate reuse across later sales.
- Current serial status or current `ProductSerialNumber.dispatch_detail_id` alone is rejected because current mutable state cannot reliably identify the historical claim being submitted.
- Serial-history event matching is unnecessary because persisted POS Return and dispatch lineage already provides a stable key.

### Pass authoritative persisted dispatch lineage into both guard call sites

Direct serialized lines will use their resolved source dispatch identity. Synthesized serialized bundle-component lines will use the component's resolved source dispatch identity. The check must not infer occurrence identity from the serial's current mutable dispatch association.

If a serialized return line cannot resolve its required source dispatch, existing validation should continue to reject it rather than weakening exclusivity to a serial-only or unscoped lookup.

### Verify only the affected behavior

Focused feature tests will demonstrate that the same serial and same dispatch remains blocked, while the same serial on a later dispatch is accepted. Bundle-component synthesis receives equivalent focused coverage. Existing closely related POS Return serial tests may be run as a focused regression group; the full suite is outside this change's verification plan.

## Risks / Trade-offs

- [Incorrect dispatch lineage could allow a duplicate claim] → Use the persisted source line/component dispatch identity already validated against the transaction snapshot, and reject missing lineage.
- [Only direct lines are corrected while bundle components retain global exclusion] → Update and test both existing guard call paths.
- [Later resale fixtures may accidentally mutate historical lineage] → Keep the earlier POS Return line pointed to its original dispatch and assign only the live serial record to the later dispatch, mirroring production behavior.

## Migration Plan

No data migration is required. Deploy the validation change and focused regression tests together. Rollback restores the previous serial-global restriction without requiring data repair.

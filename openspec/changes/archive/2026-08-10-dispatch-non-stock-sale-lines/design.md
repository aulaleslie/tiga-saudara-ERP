## Context

The archived `sell-non-stock-products-through-sales` change made saleable non-stock products available in standard Sales but deliberately excluded them from Sales Dispatch. Standard Dispatch already has a pending/approved/rejected header, approval notifications, approver metadata, detail history, quantity selection, and a stock-only inventory boundary. This change reuses that workflow as the service-completion acknowledgement without changing POS posting or adding a second service workflow.

`dispatch_details` already has a required product and quantity with nullable location and serial data. A non-stock detail can therefore retain the approved audit record while containing no inventory-specific data.

## Goals / Non-Goals

**Goals:**

- Let non-stock parent products and components participate in the existing standard Sales Dispatch quantity selection and approval lifecycle.
- Treat an approved non-stock Dispatch detail as an explicit completion/delivery acknowledgement.
- Preserve normal inventory validation, stock deduction, serial handling, and inventory transactions for stock-managed fulfilment.
- Make a Sale `DISPATCHED` only when every parent/component fulfilment obligation is approved.
- Preserve all existing POS and stock-only Sales behavior.

**Non-Goals:**

- No repair intake, work orders, technician allocation, or separate service-completion model.
- No POS flow, POS Dispatch generation, Sales Return, imports, or historical-document rewrite.
- No location, serial, or inventory transaction for non-stock fulfilment.

## Decisions

### Reuse Dispatch and DispatchDetail as the acknowledgement record

The standard Dispatch header remains pending until approved and retains its existing notifications, approval/rejection reason, approver, timestamps, and sale history. A non-stock entry creates a normal Dispatch detail with product and quantity, while `location_id` and `serial_numbers` remain null. This is the smallest design that provides partial quantities and an approval audit.

Alternative: a separate service-completion table or direct Sale status update. Rejected because it duplicates approval/audit behavior and cannot share mixed Dispatch submissions.

### Branch only at the inventory boundary

Dispatch aggregation, quantity validation, and persistence include both classifications. Location selection, location stock checks, serial validation, product/product-stock mutations, serial history, and inventory transactions run only for stock-managed details. Approval must repeat this guard server-side; hiding UI controls is not sufficient.

Alternative: continue excluding services and mark them complete automatically. Rejected because it cannot represent approved partial completion and permits mixed Sales to complete before service delivery.

### Count each persisted fulfilment obligation once

Parent detail rows and bundle component rows are separate obligations. Each is classified independently: a non-stock laptop-service parent is acknowledged by quantity, while its stock-managed RAM component is inventory-dispatched. The parent acknowledgement never absorbs the component quantity; status compares approved quantities against the same parent/component set used by Dispatch aggregation.

This avoids double counting by not treating a component as a duplicate of its parent. Existing stock-managed parent/component behaviour is otherwise retained.

### Keep POS outside the standard controller change

POS creates approved Sale/Dispatch records through its own posting adapter. This change is confined to standard Sales Dispatch aggregation, submission, approval, and status recomputation, avoiding changes to POS timing or its existing fully-dispatched semantics.

### Product-classification timing

The existing Sales behavior reloads current product classification at Dispatch processing. The initial implementation can retain that convention to avoid a schema change. If catalog reclassification between service Dispatch submission and approval must be immutable, add a server-populated inventory-fulfilment snapshot on `dispatch_details` in a follow-up; that is not required for the minimal change.

## Risks / Trade-offs

- [A non-stock detail is accidentally passed to stock logic] → enforce a server-side stock-managed guard in approval and test that every inventory side effect remains absent.
- [A mixed Sale becomes `DISPATCHED` after only stock approval] → calculate demand and approved progress across both classifications and test partial completion.
- [Bundle parent and component quantities are conflated] → retain independent parent/component rows and cover service-parent/stock-component multiplication explicitly.
- [Existing Dispatch clients submit forged service keys] → reuse authoritative aggregation validation for both classifications.
- [Classification changes while a Dispatch is pending] → retain established current-classification semantics; document the snapshot-column hardening option if that policy changes.

## Migration Plan

Deploy as an application-only change: existing Dispatch tables and nullable location/serial columns represent the acknowledgement detail. Existing historical Dispatches remain unchanged. Rollback is code rollback; already approved non-stock details remain immutable audit records and have no inventory effects.

## Open Questions

None. The agreed bundle rule is that a non-stock service parent and its stock-managed component are separate, required fulfilment obligations.

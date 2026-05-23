## Context

POS Return final approval currently executes product replacement by receiving the returned item and creating an approved replacement dispatch on the original source Sale. Replacement serial validation is global and checks only same `product_id`, active status, and draft-return locks. It does not identify the replacement serial owner before preview or approval.

Business ownership for a serial is represented indirectly by its current location: `product_serial_numbers.location_id -> locations.setting_id`. In the failing cross-owner scenario, Setting A sold `product_id X` with serial A, then the return selects replacement serial B for the same `product_id X` at a Setting B location. The current path can attach serial B to Setting A's Sale/dispatch lineage. The expected behavior is to correct Setting A's Sale commercially and create a new Setting B Sale/dispatch/payment for serial B.

This change builds on the existing POS Return approval preview/final approval flow and the existing POS checkout split-owner posting pattern. It must remain atomic and preserve brownfield Sales Return, Sale, dispatch, stock, serial, and payment conventions.

## Goals / Non-Goals

**Goals:**
- Detect whether each product-replacement line is same-owner or cross-owner from the replacement serial's current location setting.
- Show the replacement owner and generated Sale effects in approval preview.
- For cross-owner replacement, commercially adjust the original Sale as a return and create a new replacement-owner Sale with copied original Sale header/date/customer/payment context.
- Dispatch the replacement serial from its actual owner/location and record stock transactions under that owner.
- Preserve strict same-product matching by `product_id`.
- Keep final approval atomic across original Sale correction, replacement-owner Sale generation, payments, dispatch, stock, serials, and POS/Sales Return lifecycle updates.
- Ensure production schema includes `sale_return_details.execution_context` before approval plan persistence writes it.

**Non-Goals:**
- Supporting equivalent SKU replacement across different `product_id` records.
- Supporting replacement from arbitrary transfer/override locations without a serial already located under the replacement owner.
- Adding a manual UI to edit generated replacement-owner Sale allocations after approval.
- Rewriting historical POS transactions, checkouts, or already-completed returns.
- Changing cash-return behavior except where cross-owner replacement reuses the same original Sale correction semantics.

## Decisions

### Replacement serial owner is derived from current serial location

The system will resolve replacement owner from `replacementSerial.location.setting_id`. Preview and final approval will treat a missing replacement location or location setting as a blocker.

Rationale: serials do not carry a direct `setting_id`, and location ownership is already the operational stock owner. Using product ownership would not reliably represent the inventory owner when products are shared or moved.

Alternatives considered:
- Use `products.setting_id`. Rejected because it can misattribute stock if product rows are shared or serials sit in a different owner location.
- Require both product setting and location setting to match. Rejected for this change because the agreed rule is location ownership.

### Same product means same `product_id`

Replacement remains strict: the replacement serial must reference the same `product_id` as the returned POS Return line.

Rationale: this preserves the existing same-product guard and avoids introducing SKU equivalence mapping. If Setting A and Setting B maintain separate product rows for the same SKU, cross-owner replacement must be blocked until product identity is unified or a later mapping feature exists.

Alternatives considered:
- Match by product code/SKU across settings. Rejected for this change by decision; it would require additional ambiguity handling and product equivalence rules.

### Same-owner replacement keeps the existing replacement dispatch model

When `replacement_serial.location.setting_id` equals the original source owner setting, final approval will continue to receive the returned item and create replacement dispatch lineage on the original source Sale, subject to existing owner/location/quantity/product checks.

Rationale: same-owner replacement corrects fulfillment, not commercial ownership. It avoids unnecessary Sale churn for the existing supported path.

Alternatives considered:
- Always create a new Sale for every replacement. Rejected because it would change same-owner replacement behavior without a business need.

### Cross-owner replacement splits into original Sale correction plus replacement-owner Sale

When replacement owner differs from the original source owner, final approval will:
1. Receive the returned original serial/item back to the original source location.
2. Apply cash-return-style commercial correction to the original Sale detail, dispatch quantity, Sale totals, and active payments for the returned quantity/amount.
3. Create a new Sale under the replacement owner with copied original Sale date/header/customer/payment method context and a new owner-specific reference.
4. Create a Sale detail for the same `product_id`, same replacement quantity, and adjusted amount.
5. Create adjusted SalePayment rows on the replacement-owner Sale.
6. Create an approved dispatch and dispatch detail for the replacement serial from the serial's current location.
7. Mark replacement serial sold and attach it to the replacement-owner dispatch/Sale lineage.

Rationale: the replacement stock belongs to another business, so that business needs the Sale, payment, dispatch, and stock movement. The original Sale must be commercially adjusted so Setting A no longer owns the customer-facing sale amount for the returned item.

Alternatives considered:
- Attach replacement serial to the original Sale. Rejected because it corrupts owner/serial lineage.
- Block all cross-owner replacement. Rejected because the business explicitly needs cross-owner replacement with generated replacement-owner Sales.

### Payment allocation is copied as adjusted paid evidence

The replacement-owner Sale will receive adjusted paid evidence based on the returned/replaced amount from the original Sale. The original Sale payment reconciliation will use the existing active/invalidated SalePayment correction path so active payments match the adjusted original Sale total.

Rationale: both owners need auditable payment state after the split. Reusing existing payment invalidation concepts avoids mutating historical payments in place.

Alternatives considered:
- Create zero-paid replacement-owner Sales. Rejected because the requested outcome includes adjusted payment on the generated Sale.
- Move the original payment row to the replacement owner. Rejected because it loses original payment lineage and is risky across settings.

### Approval preview becomes the execution contract

Preview will include, per product-replacement line:
- original source owner/location;
- replacement serial id/number;
- replacement serial owner/location;
- execution mode: `same_owner_replacement` or `cross_owner_replacement`;
- original Sale correction amount/quantity for cross-owner replacements;
- planned generated replacement-owner Sale header, detail, payment, dispatch, stock, and serial effects.

Final approval will rebuild the preview plan and persist/execute only a zero-blocker, zero-warning plan. Any drift in replacement serial status, location, product, owner, stock, original Sale, payment, or dispatch state will block approval.

Rationale: final approval mutates multiple modules; approvers need the generated ownership effects visible before committing.

Alternatives considered:
- Infer cross-owner behavior only during execution. Rejected because it hides material owner/payment effects from approvers.

## Risks / Trade-offs

- Cross-owner replacement has a larger mutation surface than current replacement dispatch. → Keep execution in the existing final approval transaction and add focused rollback tests.
- Strict `product_id` matching may block real-world same-SKU replacements if businesses use separate product rows. → Surface a clear blocker and leave SKU-equivalence mapping out of scope.
- Copying payment context can be ambiguous for mixed payment original Sales. → Use existing active payment allocation/reconciliation rules and test single and multi-payment cases where available.
- Derived replacement-owner Sale references must be unique under the replacement owner. → Use existing Sale reference generation conventions for the target setting.
- Existing production databases may lack `sale_return_details.execution_context`. → Verify/add schema migration coverage before approval execution tests and deployment.
- Same-owner and cross-owner replacements will diverge behavior. → Make preview explicitly label the execution mode and add tests for both branches.

## Migration Plan

1. Confirm the existing migration for `sale_return_details.execution_context` runs in target environments; add a guarded repair migration if production deployments may have missed it.
2. Add any nullable linkage columns needed to connect generated replacement-owner Sales, Sale details, dispatch details, or payments back to the POS Return line and original Sale Return detail. Prefer existing metadata/link columns when sufficient.
3. Deploy code that can read old POS Return replacement records without generated replacement-owner Sales.
4. New behavior applies only to pending/future approvals. Already-completed replacement returns are not rewritten.
5. Rollback removes only new nullable linkage columns if added; it must not attempt to reverse completed approval transactions.

## Open Questions

- Which existing Sale reference generator should be used for generated replacement-owner Sales if the Sale module has more than one convention?
- Should the generated replacement-owner Sale note include the original POS Return reference, original Sale reference, and replacement serial number in a fixed text format for audit/search?
- If original Sale customer does not exist under the replacement owner, should the existing POS group customer resolver be reused to map/create the customer for the replacement owner?

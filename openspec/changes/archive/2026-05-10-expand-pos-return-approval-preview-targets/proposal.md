## Why

POS Return approval preview currently plans execution only from persisted POS Return lines. For split-owner bundled POS checkouts, that hides component-owned Sales documents created by checkout posting, so approvers cannot clearly see every generated Sale document that would be affected by approving a single returned POS item.

The preview also blocks mixed cash-return and product-replacement line resolutions even though operators need line-level resolution flexibility for realistic POS return intake.

## What Changes

- Expand approval preview planning so selected bundled POS Return lines show every generated Sales document impact, including bundle component allocations stored in other split-owner Sales documents.
- Display component-owned bundle allocation targets as explicit planned Sales Return detail rows grouped by generated source Sale, owner, location, and tax context.
- Preserve the customer-facing POS item/serial context on every planned parent and component row so approvers can trace why each Sales document is affected.
- Allow mixed `cash_return` and `product_replacement` resolutions in the approval preview by validating each line independently instead of treating mixed resolutions as a blocker.
- Keep the approval preview read-only and non-mutating; this change does not enable final approval execution.

## Capabilities

### New Capabilities

- None.

### Modified Capabilities

- `pos-return-approval-preview`: Update preview requirements to explicitly expand split-owner bundle component targets and allow mixed line-level resolutions.

## Impact

- Affects `Modules/Pos/Services/PosReturnApprovalPreviewPlannerService.php` and approval preview Blade rendering.
- May read from `sale_bundle_items`, `pos_checkout_sales`, generated `sales`, `sale_details`, dispatch details, serials, settings, locations, and taxes to build a richer non-mutating preview plan.
- Requires focused tests for split-owner bundled POS returns, explicit component target grouping, and mixed-resolution preview readiness.

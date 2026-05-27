## Why

POS split posting currently stores bundled owner-group sale detail unit prices using the full owner group subtotal. When a bundle component value is assigned to the same owner group, the parent product "Harga Satuan" is inflated even though the sale header total is correct.

The same valuation mismatch appears during POS bundled product replacement: replacement preview and generated replacement-owner Sales use the POS bundle list price instead of the source sale detail commercial amount. This causes replacement Sales to overstate the value of the item being replaced.

## What Changes

- Preserve owner-group sale totals for split bundled POS checkout, but store parent `sale_details.price` and `sale_details.unit_price` as the owner-specific parent residual amount.
- Keep bundled component value represented through `sale_bundle_items` and owner sale totals without inflating the parent sale detail unit price.
- For POS bundled product replacement, use the source sale detail commercial amount for replacement valuation in same-owner and cross-owner paths.
- Ensure approval preview, persisted Sales Return execution context, original sale correction amount, and generated replacement-owner Sale effects use the same source sale detail commercial amount.
- Add regression coverage for the observed case: two serialized bundle parent units priced at 6,100,000 each, one 15,000 component per bundle, component quantity/value assigned to the second owner group.

## Capabilities

### New Capabilities

- None.

### Modified Capabilities

- `pos-checkout-split-posting`: Parent sale detail unit price for split bundled groups must remain the parent residual commercial amount while the owner sale total may include separately persisted component value.
- `pos-return-approval-preview`: POS bundled product replacement preview must value replacement effects from the source sale detail commercial amount rather than the POS bundle list price.
- `pos-return-cross-owner-replacement`: Cross-owner replacement generated Sales and original sale corrections must use the source sale detail commercial amount for bundled replacement lines.

## Impact

- Affected modules:
  - `Modules/Pos/Services/PosCheckoutSplitPlannerService.php`
  - `Modules/Pos/Services/Adapters/InlinePosCheckoutPostingAdapter.php`
  - `Modules/Pos/Services/PosReturnApprovalPreviewPlannerService.php`
  - `Modules/Pos/Services/PosReturnApprovalPlanPersistenceService.php`
  - `Modules/Pos/Services/PosReturnLifecycleService.php`
- Affected persisted data semantics:
  - `sale_details.price`
  - `sale_details.unit_price`
  - `sale_details.sub_total`
  - `sale_bundle_items.price`
  - `sale_bundle_items.sub_total`
  - replacement-owner `sales.total_amount`, `sale_details`, and `sale_payments`
- No new external dependencies or public API changes.

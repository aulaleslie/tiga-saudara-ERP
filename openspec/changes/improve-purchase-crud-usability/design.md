## Context

Purchase create and edit share the `ProductCart` Livewire component and persist cart values through `PurchaseNormalizer`. A row currently begins from a unit price, then calculates its post-discount subtotal, DPP, and tax using the selected per-line tax and the purchase-level `is_tax_included` flag. Purchase detail already uses narrow Livewire editors for the supplier purchase number and tax reference number, with `purchases.update`, active-setting ownership, and archived-document checks. Receiving is a conventional POST form whose location picker is a nested Livewire component.

The change spans all of those seams but requires no schema change and must preserve historical purchase values and approved/received stock behavior.

## Goals / Non-Goals

**Goals:**

- Provide an authorized navigation shortcut from a purchase row to the existing cross-business price page.
- Permit only the purchase note to be changed after receiving, using the current narrow-editor authorization model.
- Let users set a row's final tax-inclusive total without storing contradictory unit price, discount, DPP, tax, or subtotal values.
- Present rejected receiving validation consistently in Bahasa Indonesia while retaining submitted form data.

**Non-Goals:**

- No new permission, route, database column, global-discount allocation, shipping allocation, or change to approved purchase/stock accounting.
- No ability to edit other purchase header or detail fields after receipt.
- No alteration of the existing cross-business price page's business scope or write behavior.

## Decisions

### Gate product links with the target page permission

The cart will render a route link only when `products.manage_cross_business_prices` is allowed. The existing route middleware remains mandatory for direct requests. This reuses the target capability's permission rather than adding a purchase-specific permission. An unconditional link was rejected because it advertises inaccessible functionality.

### Use a narrow note editor on the detail page

Implement the note interaction with the supplier purchase-number editor pattern: load with archived records, verify the active setting, require `purchases.update`, reject archived purchases, validate `nullable|string|max:1000`, and update only `note`. This deliberately bypasses the full edit screen's received-status block because the operation cannot change quantity, money, supplier, or stock. Reopening the full edit flow was rejected because it can recreate detail rows and alter monetary state.

### Treat final line total as an input inversion, not an independent persisted value

The new `Total Baris` is the final line total after that line's discount, including its selected tax, and before global discount and shipping. On change, calculate a compatible effective per-unit amount, then back-calculate the editable unit price while preserving quantity, tax ID, discount type/input, and tax-inclusion mode. The canonical persisted values remain the current `price`, `unit_price`, `product_discount_amount`, `sub_total`, `product_tax_amount`, and tax ID; no line-total column is introduced.

For fixed discounts, add the per-unit discount back after deriving the effective price. For percentage discounts, reverse the percentage before assigning the unit price. For tax-included lines, divide the requested line total by quantity before splitting DPP/tax. For tax-excluded lines, divide by quantity and `(1 + tax rate)` before applying the same calculator. The existing calculator then produces the authoritative DPP, tax, and subtotal. This prevents divergence between UI display, normalized persistence, reports, and inventory valuation.

### Keep global discount and shipping outside line-total editing

Global discount and shipping stay header-level adjustments. A line total neither includes them nor reallocates them. Allocating them would create ambiguous rounding and change inventory-cost semantics. The UI will state this scope beside the field.

### Make receiving errors visible in both client and server paths

The receiving view will expose a shared validation-summary area and field-level error styling/message. Client-side zero-quantity detection writes the same Indonesian message and focuses the error region instead of relying only on `alert`; server validation continues to reject crafted or JavaScript-disabled requests. The location dropdown receives a reliably rendered error value and invalid state after redirect. Regular Laravel old input remains the source for repopulation.

## Risks / Trade-offs

- [Money rounding for quantity and percentage discounts] → Round calculated monetary values consistently with `PurchaseNormalizer`; cover tax-included and tax-excluded multi-quantity tests.
- [Final total conflicts with a later tax/discount/quantity change] → Treat the latest user change as authoritative and recalculate the row; label the total as derived after later changes.
- [Completed purchase note updates bypass full lifecycle guards] → Restrict the operation to one text field, current setting, non-archived documents, and the existing update permission.
- [Client validation can be bypassed] → Keep the controller's server validation and localized messages authoritative.
- [Nested Livewire picker error rendering may be stale] → Pass current validation error and render an invalid/error state in the parent-visible control.

## Migration Plan

Deploy as an additive application change with no data migration. Existing purchase detail rows retain their stored pricing values. Rollback consists of reverting UI/component changes; no persisted data requires transformation because a submitted line total is normalized into existing fields.

## Open Questions

None. The proposal fixes the row-total contract as post-line-discount, tax-inclusive, and pre-header-adjustment.

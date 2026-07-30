## Why

The purchase create, detail, and receiving workflows leave important information inaccessible or ambiguous: authorized users cannot jump from a selected purchase product to its cross-business prices, cannot amend a completed purchase note, and receive unclear validation feedback for missing locations or zero quantities. Purchase users also need to enter a final price per line without breaking the established tax and discount accounting model.

## What Changes

- Show a product-name link in purchase product rows only to users authorized to manage cross-business product prices; retain the protected route as the authorization boundary.
- Add a narrowly scoped, permission-gated inline editor for a purchase note on the purchase detail page, including fully received purchases, while preserving archived and cross-business restrictions.
- Add an editable final total for each purchase line and derive the compatible unit price, tax, DPP, and subtotal from the active quantity, line discount, selected tax, and tax-inclusion mode.
- Define the final line total as post-line-discount, tax-inclusive, and before document-level discount and shipping.
- Make missing-location and zero-receipt validation consistently visible in the purchase receiving form, preserve entered values after rejection, and use Bahasa Indonesia messages.

## Capabilities

### New Capabilities

- `purchase-detail-inline-maintenance`: Permission-scoped narrow inline updates available from the purchase detail workflow.
- `purchase-line-final-price`: Editing and deterministic tax-aware normalization of a purchase line's final total.
- `purchase-receiving-validation-feedback`: Clear, localized, persistent validation feedback for rejected purchase-receiving submissions.

### Modified Capabilities

- `cross-business-product-price-management`: Authorize an additional entry point from purchase product rows.
- `purchase-creation`: Preserve tax-inclusion and line-tax rules while accepting a final line-total input in create and edit workflows.
- `purchase-receiving-notes`: Strengthen receiving-form rejection feedback beyond the existing empty-submit guard.

## Impact

- Affected UI/components: `App\\Livewire\\Purchase\\ProductCart`, purchase create/edit cart views, purchase detail view, and the receiving view/location dropdown.
- Affected domain logic: `PurchaseNormalizer`, purchase line subtotal/tax calculation, and the receiving controller validation response path.
- Affected authorization: existing `products.manage_cross_business_prices` and `purchases.update`; no new permission is required.
- Affected verification: Livewire/component, feature, authorization, tax-inclusion, and receiving validation tests.

## Context

The POS sell cart already renders bundle-aware lines with `bundle_id` and `bundle_name`, and the cart snapshot already includes `bundle_price`, `bundle_items`, `unit_price`, `qty`, and `line_total`. The existing bundle selection modal is used before a bundled line is added to the cart; this change needs a separate read-only detail dialog after the line is already in the cart.

The dialog is for cashier confirmation, not for editing bundle composition or recalculating checkout totals. The cart table should remain compact and stable, especially on POS screens where inline row expansion would make quantity and payment actions harder to scan.

## Goals / Non-Goals

**Goals:**

- Let cashiers click `Paket: <bundle name>` on a POS cart row to open a read-only detail dialog.
- Display bundle composition from the selected cart line snapshot.
- Display simple price composition: derived base product price, bundle add-on price, final unit price, and line total.
- Keep the dialog independent from checkout, stock deduction, tax, discount, and bundle selection flows.
- Preserve the existing cart table layout when opening or closing the dialog.

**Non-Goals:**

- Do not add or change backend routes unless implementation discovers the existing cart snapshot is insufficient.
- Do not edit bundle definitions from the POS cart.
- Do not show tax or discount breakdowns in the dialog.
- Do not change cart pricing, checkout posting, stock allocation, or receipt behavior.
- Do not replace the existing bundle selection modal.

## Decisions

1. Use the cart snapshot as the source of truth for dialog content.

   The current snapshot already carries the data needed to describe the selected bundle as it exists on the cart line. This avoids an extra fetch and avoids showing live catalog data that might differ from what the cashier selected earlier.

   Alternative considered: fetch bundle details from `/pos/sell/products/{product}/bundles` when the label is clicked. That would add loading and error states and could display a current catalog definition rather than the cart-line snapshot.

2. Add a dedicated read-only bundle detail modal.

   The existing bundle selection modal is interactive and built for choosing a bundle before cart insertion. Reusing it for read-only detail would mix two different user intents and increase the risk of accidental behavior changes.

   Alternative considered: expand details inline under the cart row. This would disrupt table height and scanning during POS operation.

3. Make the existing `Paket: <bundle name>` label the trigger.

   The label is already the visual signifier for a bundled row. Turning it into a button-like control keeps the cart compact and avoids adding another action column control.

   Alternative considered: add a separate `Detail` or `Lihat Paket` button. That is clearer in isolation but adds visual noise to every bundled cart row.

4. Derive base product price on the client for display only.

   Since the backend exposes `unit_price` and `bundle_price`, the dialog can compute `base_product_price = unit_price - bundle_price` for presentation. The calculation does not affect cart totals and should be clamped to zero for display if malformed data is encountered.

   Alternative considered: expose a new `base_unit_price` field from the backend. This would be more explicit, but it is unnecessary for a read-only display based on already available fields.

## Risks / Trade-offs

- Snapshot fields missing on older or loaded carts -> The dialog should tolerate missing `bundle_items` and show an empty-state message instead of failing.
- Click target conflicts with cart row controls -> Use a scoped class such as `js-bundle-detail` and event delegation from the cart body so existing quantity, serial, and remove handlers remain isolated.
- Derived base price can be confusing after manual price override -> Label it as `Harga produk` for the displayed row and keep it read-only; do not imply it is a persisted accounting field.
- Modal content can grow for large bundles -> Use a scrollable item area within the modal body while keeping the header and footer usable.

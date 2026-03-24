## Context

The current POS receipt is generated via `PosReceiptService::getReceiptData()` and rendered in `receipt.blade.php`. It suffers from incorrect property mapping for multi-payments and a lack of granular unit conversion information for items.

## Goals / Non-Goals

**Goals:**
- Correct the payment amount display for all payment methods.
- Include itemized unit conversion breakdowns (e.g., "1 BOX @ 270.000").
- Modernize the receipt layout to match the thermal printer standard provided in the reference image.
- Ensure the business contact information (Email, Phone) is accurately presented.

**Non-Goals:**
- Modification of the checkout finalization logic or database schema.
- Support for hardware-specific ESC/POS commands (view-based printing only).
- Changes to non-POS invoice templates.

## Decisions

- **Data Sourcing**: Switch from `SaleDetail` to `PosTransactionLine` for item data in `PosReceiptService`. This allows access to `conversion_id` and unit information that is already snapshotted during the POS session.
- **Relationship Loading**: Use `loadMissing(['transaction.lines.conversion.unit'])` on the `PosCheckout` model to efficiently retrieve the necessary data for unit breakdowns.
- **Payment Accessor**: Explicitly use the `amount` accessor on `PosCheckoutPayment` entities to ensure the decimal representation of `amount_minor_units` is used, fixing the "0 nominal" bug.
- **Layout Engine**: Use pure CSS/HTML in `receipt.blade.php` with a fixed-width `80mm` container and dashed dividers to emulate a physical receipt.

## Risks / Trade-offs

- **Data Availability**: If a `PosCheckout` is created without an associated `PosTransaction`, the line data might fall back to `SaleDetail`, losing the unit breakdown. This is acceptable as a fallback.
- **Visual Accuracy**: Variations in browser print engines may affect the exact layout. We will use standard CSS print media queries to mitigate this.

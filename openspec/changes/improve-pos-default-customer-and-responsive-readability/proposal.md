## Why

The POS already stores a per-business walk-in customer, but a new cart still appears unresolved until a cashier explicitly selects a customer, and the business-setting selector is difficult to use with a large global customer list. The fixed-height POS shell also uses typography and payment-area sizing that can become too small or clip the checkout action on shorter desktop and tablet-landscape viewports.

## What Changes

- Make the existing Business Settings `Pelanggan Walk-In POS` control searchable across the global customer list while preserving the per-business `pos_walk_in_customer_id` mapping.
- Resolve and visibly present the active business's configured walk-in customer as the default for a new or cleared POS cart, while retaining a distinct state for a cashier-selected customer.
- Preserve global customer identity and existing source-business walk-in fallback behavior during split checkout; no customer-to-business ownership restriction is introduced.
- Increase typography throughout the POS sell workspace using a proportional hierarchy that keeps totals, headings, controls, content, and metadata visually relative.
- Make the payment action area responsive so the checkout and save-and-new controls remain fully visible and usable on supported desktop and tablet-landscape viewport sizes.
- Add regression coverage for default-customer resolution and define a viewport verification matrix for typography and checkout-action visibility.

## Capabilities

### New Capabilities

- `pos-default-customer-experience`: Searchable global walk-in customer configuration and default-versus-explicit customer behavior on the POS sell cart.
- `pos-responsive-sell-shell`: Proportional POS typography and a non-clipping payment action area across supported viewport sizes.

### Modified Capabilities

None. Existing split-posting customer resolution, global customer identity, payment behavior, and POS authorization requirements remain unchanged.

## Impact

- Business Settings customer selection and validation presentation.
- POS cart customer snapshot/resolution, customer display, clear/reset behavior, and associated feature tests.
- POS sell shell Blade/CSS, especially typography tokens, height breakpoints, payment-card layout, and action controls.
- Existing `settings.pos_walk_in_customer_id` and the global `customers` table are reused; no schema migration or new dependency is expected.

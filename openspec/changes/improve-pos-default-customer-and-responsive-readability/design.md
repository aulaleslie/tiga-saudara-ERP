## Context

Business settings already persist a nullable `settings.pos_walk_in_customer_id`, and the settings page renders all global customers in a plain select. POS finalization and split-group posting already use source-setting walk-in mappings as fallback and accept global customer identity regardless of customer `setting_id`. The interactive cart snapshot, however, resolves only an explicitly selected customer, so a configured default is not represented in the customer panel and the cart may appear unresolved.

The sell shell is a fixed `100dvh` grid with compact, scattered font sizes and height-specific reductions. Its payment card body hides overflow while its content and two large action buttons share a constrained grid row. On short viewports this can clip the bottom of the checkout action. Increasing typography independently would increase that risk, so readability and payment-area resilience must be designed together.

## Goals / Non-Goals

**Goals:**

- Reuse the existing per-business walk-in customer mapping and global customer records.
- Make the Business Settings mapping searchable without adding a schema field or external dependency.
- Represent default and explicitly selected customers distinctly in cart state and UI.
- Preserve source-business fallback behavior for split checkout.
- Establish a coherent, larger typography scale throughout the POS sell workspace.
- Guarantee a fully visible payment action area at supported desktop and tablet-landscape sizes.

**Non-Goals:**

- Scoping or duplicating customers by business.
- Changing split ownership, numbering, tax, pricing, debt eligibility, or payment rules.
- Adding a per-user POS customer default.
- Redesigning the POS information architecture or enabling the sell workspace on currently unsupported portrait sizes.
- Changing receipt-print typography.

## Decisions

### Reuse `pos_walk_in_customer_id` as the only default-customer configuration

The settings form will enhance the existing selector with the project's available Select2 asset and continue submitting the existing field. The option set remains global. This avoids a migration and matches the domain rule that multiple businesses can point to the same customer record.

An AJAX search endpoint is not required for the initial change because the page already loads the option collection. If production customer volume makes initial rendering measurably expensive, remote search can be proposed separately.

### Model default resolution separately from explicit selection

The cart's persisted `selected_customer_id` remains reserved for cashier intent. Customer snapshot resolution will apply this precedence:

1. A valid `selected_customer_id` produces `resolution_source=selected`.
2. Otherwise, the active setting's valid `pos_walk_in_customer_id` produces `resolution_source=default`.
3. Otherwise, customer resolution remains `none`.

The snapshot will expose enough mapped effective-customer data for the existing renderer to show either selected or default customers without an additional browser request. Clearing a selected customer writes `selected_customer_id=null`, which naturally restores default resolution. Default resolution must not apply tier pricing as though the cashier explicitly selected a tier customer unless existing pricing policy already treats the effective walk-in customer that way; current explicit-tier behavior remains unchanged.

Alternative considered: write the walk-in ID into `selected_customer_id` when creating an empty cart. This was rejected because it erases the distinction between default and cashier intent and can bypass each source setting's fallback during split posting.

### Leave split-group resolution authoritative per source business

The UI-level default belongs to the active terminal business, while final split groups continue using the existing resolver: explicit global customer first, otherwise the source setting's walk-in customer. No `customers.setting_id` filter will be added. This retains the behavior specified by `pos-checkout-split-posting` even when several source settings point to the same global Cash customer.

### Introduce semantic typography tokens for the POS sell workspace

The sell shell CSS will define a small set of scoped custom properties for metadata, body, control, heading, and total text. Existing selectors will be consolidated onto those roles, with bounded responsive adjustments rather than independent reductions at every breakpoint. Component-specific exceptions remain permissible for dense technical content, but user-facing text must not fall below the agreed readable role for supported viewports.

Alternative considered: apply a single transform or root font-size multiplier. This was rejected because it scales spacing and controls unpredictably and preserves the current inconsistent hierarchy.

### Separate flexible payment content from non-shrinking actions

The payment shell will distinguish a flexible content region from an action region with `flex-shrink: 0`. The grid row and card body will allow the non-action region to compact or use bounded internal overflow; the overall desktop sell page remains fixed to the viewport. At narrow landscape widths, actions may wrap or stack while retaining full height and label visibility.

The layout will be checked at 1366x768, 1280x720, and 1024x768 CSS pixels, plus the existing portrait-lock boundary. Browser zoom and OS chrome can change the effective viewport, so CSS must respond to actual viewport dimensions rather than device-name assumptions.

## Risks / Trade-offs

- [Risk] Applying default resolution in the wrong layer could mark it as an explicit customer and change split posting. → Keep `selected_customer_id` nullable for defaults and add regression tests around multi-source resolution.
- [Risk] A configured customer may later be deleted or become invalid. → Resolve by existing customer ID on every snapshot and fall back to the unresolved state with existing guards.
- [Risk] Loading every global customer into Select2 can become expensive at very high volume. → Reuse current loading behavior for this bounded change and measure before introducing a remote endpoint.
- [Risk] Larger typography reduces information density and may expose additional clipping. → Use semantic tokens, reserve action height first, and verify the defined viewport matrix.
- [Risk] CSS rules embedded across partials may override the new scale. → Inventory sell partials and overlays during implementation and add scoped overrides or consolidate conflicting declarations deliberately.

## Migration Plan

No data migration is required. Deploy the settings selector, cart resolver/UI, and responsive CSS together, then run focused POS and settings tests followed by viewport verification. Rollback consists of reverting the application changes; existing `pos_walk_in_customer_id` values remain valid and unchanged.

## Open Questions

None. The default customer remains global, each business controls its own mapping, and existing source-setting fallback remains authoritative for split checkout.

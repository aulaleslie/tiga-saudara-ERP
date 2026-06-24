## Context

Terminal Harga is rendered by `App\Livewire\PricePoint\Browser` and currently queries active-setting product prices through `product_prices.setting_id`, then displays all available tiers (`sale_price`, `tier_1_price`, and `tier_2_price`) on each product card. The page is opened from the authenticated ERP shell but uses its own layout and does not participate in POS cart state.

Existing POS and Sales flows already define the customer-tier price semantics used elsewhere in the ERP: customers with tier `WHOLESALER` use `tier_1_price`, customers with tier `RESELLER` use `tier_2_price`, and missing or non-positive tier prices fall back to `sale_price`. Customers are treated as global master data for this Terminal Harga use case; `customers.setting_id` may exist on historical rows but must not constrain customer lookup or tier resolution.

## Goals / Non-Goals

**Goals:**
- Show normal non-tier product prices by default.
- Let the user search and select a global customer from Terminal Harga.
- Recompute displayed product prices immediately from the selected customer's tier.
- Keep product price lookup scoped to the active setting's `product_prices` row.
- Hide non-applicable tiers so each product card answers "the price for the current customer" with one price.
- Preserve scanner-friendly product search, pagination, currency formatting, and conversion display behavior.

**Non-Goals:**
- No customer creation flow is added to Terminal Harga.
- No changes to POS cart, Sales cart, checkout, import, or bundle pricing behavior.
- No schema migration or customer data cleanup.
- No attempt to make customer `setting_id` meaningful for Terminal Harga.
- No changes to conversion pricing beyond preserving the current active-setting conversion display.

## Decisions

### Use `PricePoint\Browser` as the owner of customer selection state

Terminal Harga should store selected customer state directly in the existing Livewire component, including the selected customer id, label, tier, customer search text, and dropdown result list.

Rationale: the selected customer only affects this page's display calculation. Keeping the state local avoids adding server routes or coupling the public price terminal to POS cart session state.

Alternative considered: reuse the POS customer search endpoint and JavaScript customer picker. Rejected because Terminal Harga is already Livewire-driven and does not need POS session middleware, cart mutations, or POS-specific UI behavior.

Alternative considered: embed the existing `modules.people.customer-search-dropdown` component. Rejected for the initial implementation because Terminal Harga uses a Tailwind-oriented layout and needs customer metadata (`tier`) directly in the parent component to compute prices. A shared component can still be refactored later if repeated behavior grows.

### Search customers globally

Customer search and selected-customer lookup will query `customers` without filtering on `setting_id`.

Rationale: the clarified business rule is that customers are global for Terminal Harga, and any `setting_id` value on customer rows must be ignored for this flow.

Alternative considered: filter customers by active setting. Rejected because it would hide valid global customers and incorrectly prevent their tier from affecting displayed prices.

### Keep product prices setting-scoped

Product card data will continue to come from the active setting's `product_prices` row. The component may continue selecting `sale_price`, `tier_1_price`, and `tier_2_price` as raw data, but the Blade view should render only the resolved contextual price.

Rationale: products can have outlet-specific price configuration, so the current active setting remains the correct source for product price values. Customer selection decides which column to use, not which setting's price row to read.

### Centralize contextual price resolution

The component should expose a small resolver for product display prices:

- no selected tier: `sale_price`;
- `WHOLESALER`: positive `tier_1_price`, otherwise `sale_price`;
- `RESELLER`: positive `tier_2_price`, otherwise `sale_price`;
- unknown tier: `sale_price`.

The resolved payload should include the raw price and a short label such as `Umum`, `Grosir`, or `Reseller` so the UI can communicate why that price is shown without listing hidden tiers.

Rationale: this mirrors existing POS and Sales tier semantics while keeping Terminal Harga read-only and predictable.

Alternative considered: calculate the display price in Blade. Rejected because tier fallback is business logic and should be covered by component-level tests.

### Reset pagination on customer changes

Selecting or clearing a customer should reset the product paginator to page 1, the same way product search changes do.

Rationale: a customer change can alter every visible card's price. Returning to the first page gives a deterministic view and avoids stale pagination expectations after a contextual filter-like change.

## Risks / Trade-offs

- [Risk] Customer rows from historical migrations may have nullable, stale, or cross-setting `setting_id` values. -> Mitigation: never filter customer search or selected-customer lookup by `setting_id`; add coverage where selected customer and active product setting differ.
- [Risk] Displaying one price hides other tier values from users who previously used Terminal Harga as a tier comparison tool. -> Mitigation: the new requirement is customer-context pricing; show the selected price label and selected customer state clearly.
- [Risk] Duplicating tier resolution rules could drift from POS/Sales behavior. -> Mitigation: keep the rule explicit and covered by focused tests; consider extracting a shared resolver only if another screen needs the same read-only behavior.
- [Risk] Livewire customer search could interfere with scanner-focused product search focus. -> Mitigation: keep product `searchNow` refocus behavior, and only focus customer search when the customer dropdown is actively used.

## Migration Plan

No database migration is required. Deploy as an application-only change.

Rollback is limited to reverting the Livewire component/view/test changes. Existing customer and product price data are not transformed.

## Open Questions

None. The user clarified that Terminal Harga customer selection is global and ignores `customers.setting_id`.

## Context

The application has standalone Pembayaran Penjualan Global and Pembayaran Pembelian Global pages. Each page composes a global-mode summary-card Livewire component with a global-mode table component; the table owns business/date filter state and exchanges events with the summary cards. Dedicated global routes and existing services provide cross-setting read-only detail/history and atomic multi-invoice payment creation.

Customer and supplier indexes are not constrained by the active setting. Sales and purchases retain `setting_id`, so an entity detail workspace must hold the entity identity fixed while allowing the existing business filter to constrain related documents. Supplier detail currently contains an ordinary purchase table, while customer detail contains only profile information.

## Goals / Non-Goals

**Goals:**

- Render an additional, full global sales-payment workspace beneath customer details and constrain every result and summary to the displayed customer.
- Render an additional, full global purchase-payment workspace beneath supplier details and constrain every result and summary to the displayed supplier.
- Preserve the existing standalone workspaces, global eligibility calculations, business/date filtering, card interaction, search, sorting, pagination, detail/history routes, and multi-payment services.
- Enforce the same global-access and payment-create permission split in embedded and standalone contexts.
- Prevent Livewire requests from changing the server-established customer or supplier constraint.

**Non-Goals:**

- Removing, relocating, or behaviorally changing either standalone Pembayaran Global page.
- Scoping customer or supplier lists by `setting_id`.
- Duplicating customers/suppliers, matching parties by name, or changing their schema and creation rules.
- Introducing new payment records, allocation algorithms, eligibility rules, or mutations.
- Removing existing content from customer or supplier detail pages, including the supplier's existing purchase section.

## Decisions

### Reuse the existing workspace components with optional entity context

Extend the four existing global-mode components with optional entity identifiers: `customerId` for `SaleTable` and `SaleSummaryCards`, and `supplierId` for `PurchaseTable` and `PurchaseSummaryCards`. Standalone pages omit the identifiers and therefore retain their current global result set. Detail pages pass the displayed entity identifier to both components.

This avoids copying query and UI implementations that would drift from the standalone pages. A separate set of entity-specific components was rejected because it would duplicate eligibility, filtering, summaries, and action behavior.

### Treat the entity constraint as immutable server context

Entity identifiers passed from the route-bound detail model will be Livewire locked properties. Every sales query, purchase query, and payment-based recent-activity summary query will apply the matching entity constraint. The component must not infer the constraint from URL query parameters or mutable browser state.

For payment summary queries rooted at `SalePayment` or `PurchasePayment`, apply the constraint through the related sale or purchase. This keeps recent-payment counts and totals consistent with the table and outstanding/overdue cards.

### Compose business filters with entity constraints

The fixed `customer_id` or `supplier_id` predicate is always applied in embedded mode. Existing `globalBusinessFilters` continue to constrain `sales.setting_id` or `purchases.setting_id`; an empty business selection continues to mean all businesses. Date ranges, selected summary cards, search, status eligibility, sorting, and pagination remain additive predicates.

Customer and supplier queries themselves receive no active-setting constraint.

### Add a reusable Blade composition for each workspace

Extract or introduce a small Blade partial/component for each global-payment page composition so standalone and embedded contexts render the same summary cards and table with explicit optional entity inputs and unique Livewire keys. Existing standalone routes and page templates remain in place and consume the shared composition without an entity identifier. People detail views consume it with the route-bound customer or supplier identifier.

### Preserve existing authorization boundaries

Customer detail continues to require `customers.show`; supplier detail continues to require `suppliers.show`. The embedded sales workspace renders only with `salePayments.global.access`, and the purchase workspace only with `purchasePayments.global.access`. Existing components retain their defensive permission checks. Existing global detail/history routes remain protected by global access, while create/store actions continue to require both global access and the corresponding `*.create` permission.

Users who can view the People detail but lack global-payment access see the existing detail content without the embedded workspace. Users with global access but without create permission see the existing read-only global actions.

### Retain existing global action destinations

Embedded rows use the same dedicated global detail, history, and payment routes as the standalone workspace. This keeps cross-setting authorization and mutation behavior centralized. Context-aware return navigation is not required by this change; existing route destinations and post-payment redirects remain valid.

## Risks / Trade-offs

- **[Summary and table predicates can diverge]** → Add equivalent entity filtering to every summary query and table query, including recent-payment relationship queries, with focused consistency tests.
- **[A mutable Livewire identifier could expose another party's data]** → Mark entity identifiers locked and initialize them only from the server-rendered detail view; test attempted property mutation.
- **[Shared event names could affect another workspace on the same page]** → Render only the matching sales or purchase global workspace per detail page and use stable, context-specific Livewire keys. Preserve current event contracts.
- **[The supplier page becomes longer because its ordinary purchase table remains]** → Keep existing content to avoid an unrelated behavioral removal; place and label the global-payment workspace distinctly beneath it.
- **[Reusable markup extraction could regress standalone pages]** → Add regression tests showing standalone pages still render without entity constraints and preserve their full cross-party results.

## Migration Plan

1. Extend table and summary components with optional locked entity inputs and query constraints.
2. Share the existing page composition and update standalone pages to use it without an entity input.
3. Render the entity-constrained composition conditionally on customer and supplier details.
4. Deploy without schema or data migration.

Rollback removes the embedded render calls and optional entity context while leaving all payment data and standalone global-payment pages intact.

## Open Questions

None. Existing navigation targets and supplier-detail content are intentionally preserved.

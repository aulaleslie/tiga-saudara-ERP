## 1. Sales product selection

- [x] 1.1 Update the standard Sales product search API and Livewire fallback to return active products that are marked `is_sold`, regardless of `stock_managed`.
- [x] 1.2 Preserve Sales-cart pricing, tax, discount, quantity, and presentation behavior when the selected product is non-stock-managed.
- [x] 1.3 Add focused feature/Livewire coverage proving a saleable non-stock product is searchable and can be added to the standard Sales cart, while a non-saleable product remains excluded.

## 2. Sales document inventory boundaries

- [x] 2.1 Update `SaleService` create/update stock validation to validate only stock-managed parent products and stock-managed bundle components, using authoritative persisted product classification.
- [x] 2.2 Preserve non-stock line persistence, financial totals, and existing zero-cost sales snapshot behavior through standard Sale creation and update.
- [x] 2.3 Add focused Sales-service/feature coverage for service-only Sales with no inventory, mixed Sales that still reject unavailable physical goods, and non-stock zero-cost snapshots.

## 3. Inventory-only dispatch fulfillment

- [x] 3.1 Exclude non-stock parent products and non-stock bundle components from Sales dispatch aggregation and the dispatch page.
- [x] 3.2 Harden dispatch submission and approval to skip non-stock demand and prevent dispatch details, location/serial validation, stock mutation, and inventory transactions for it.
- [x] 3.3 Update dispatch-status calculation to use only stock-managed fulfillment demand, retaining `APPROVED` for service-only Sales and reaching `DISPATCHED` for mixed Sales after all physical demand is approved.
- [x] 3.4 Add regression coverage for service-only, stock-only, mixed, and non-stock-parent/stock-component bundle dispatch scenarios.

## 4. Verification

- [x] 4.1 Run the focused Sales and dispatch feature tests added or affected by this change.
- [x] 4.2 Run the appropriate broader Laravel test command and record any unrelated existing failures separately.

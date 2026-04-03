## 1. Shared Purchase Normalization

- [x] 1.1 Introduce a shared purchase normalization path that converts purchase header and detail inputs into persistence-safe values based on the current setting `is_pkp`.
- [x] 1.2 Implement non-PKP normalization rules that strip `tax_id`, zero `product_tax_amount`, zero purchase header tax fields, and recompute persisted line subtotals and purchase totals using tax-excluded amounts.

## 2. Purchase Flow Integration

- [x] 2.1 Update the Livewire purchase create flow to use the shared normalization path before saving purchase headers and details.
- [x] 2.2 Update the Livewire purchase edit flow to use the same normalization path before updating purchase headers and recreating purchase details.
- [x] 2.3 Align the controller-backed purchase store and update paths with the same shared normalization behavior so all purchase writers enforce one invariant.

## 3. Cart And UI Hygiene

- [x] 3.1 Prevent non-PKP purchase cart flows from auto-seeding hidden tax from product purchase tax defaults where the user cannot explicitly manage it.
- [x] 3.2 Ensure non-PKP purchase edit/cart restore does not silently retain hidden tax state that conflicts with the persistence rule.
- [x] 3.3 Align non-PKP purchase UI affordances, including tax-only edit fields, with the normalized non-tax workflow.

## 4. Regression Coverage

- [x] 4.1 Add automated coverage for non-PKP purchase creation through the Livewire create flow with hidden tax-bearing cart state.
- [x] 4.2 Add automated coverage for non-PKP purchase updates through the Livewire edit flow when restored cart state contains hidden tax.
- [x] 4.3 Verify controller-backed purchase creation or update continues to persist tax-free non-PKP purchases with recomputed tax-excluded totals.

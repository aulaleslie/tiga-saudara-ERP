## 1. Shared Sale Normalization

- [x] 1.1 Introduce a shared sale normalization path that converts sale header and detail inputs into persistence-safe values based on the current `is_pkp` setting.
- [x] 1.2 Implement non-PKP normalization rules that strip `tax_id`, zero `product_tax_amount`, zero sale header tax fields, and recompute persisted line subtotals and sale totals using tax-excluded values.

## 2. Sale Flow Integration

- [x] 2.1 Update the Livewire sale create flow to use the shared normalization path before saving sale headers and details.
- [x] 2.2 Update the Livewire sale edit flow to use the same shared normalization path before updating sale headers and recreating sale details.
- [x] 2.3 Align the controller-backed sale store and update paths with the same shared normalization behavior through the sale service boundary.

## 3. Cart Restore And Hygiene

- [x] 3.1 Adjust non-PKP sale edit/cart restore behavior so restored rows do not silently preserve hidden tax-bearing state that conflicts with normalized persistence.
- [x] 3.2 Ensure non-PKP sale cart recalculation remains consistent with normalized tax-excluded persistence when quantities, prices, discounts, or tax-inclusion settings change.

## 4. Regression Coverage

- [x] 4.1 Add automated coverage for non-PKP sale creation when hidden tax-bearing cart state is present at save time.
- [x] 4.2 Add automated coverage for non-PKP sale updates through the Livewire edit flow when restored cart state contains hidden tax-bearing values.
- [x] 4.3 Verify controller-backed sale creation or update persists normalized tax-free non-PKP sale totals and detail subtotals.

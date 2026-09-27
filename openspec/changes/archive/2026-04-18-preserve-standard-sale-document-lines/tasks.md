## 1. Cart Row Behavior

- [x] 1.1 Add or update tests proving standard Sales product search selection creates a new cart row when the same product is selected twice.
- [x] 1.2 Add or update tests proving standard Sales bundle selection creates a new cart row when the same product and bundle are selected twice.
- [x] 1.3 Inspect `ProductCart` add paths and adjust only if any same-product append behavior still exists in standard Sales create/edit.

## 2. Document Persistence

- [x] 2.1 Update `SaleService::createSale()` to normalize the current cart rows directly instead of aggregating them before normalization.
- [x] 2.2 Update `SaleService::updateSale()` to normalize the current cart rows directly instead of aggregating them before normalization.
- [x] 2.3 Keep `SaleCartAggregator` available for explicit aggregation use cases, but remove it from standard Sales document save/update.
- [x] 2.4 Verify each preserved parent sale detail creates only its own associated bundle item rows.

## 3. Edit Hydration

- [x] 3.1 Add or update tests proving duplicate saved `sale_details` rows load as separate cart rows on standard Sales edit.
- [x] 3.2 Confirm Livewire edit hydration preserves duplicate saved rows without collapsing matching product/tax/bundle rows.
- [x] 3.3 Confirm the legacy controller edit cart rebuild path remains compatible with duplicate saved rows.

## 4. Dispatch Compatibility

- [x] 4.1 Add or update tests proving dispatch view aggregates duplicate sale details by product/tax/bundle.
- [x] 4.2 Add or update tests proving dispatch validation uses aggregate remaining quantity from duplicate sale details.
- [x] 4.3 Confirm sale status calculation after approved dispatch still compares total dispatched quantity against summed sale detail and bundle quantities.

## 5. Regression Coverage

- [x] 5.1 Add create-sale regression coverage for duplicate non-bundle rows with matching product/tax values.
- [x] 5.2 Add update-sale regression coverage for duplicate non-bundle rows with matching product/tax values.
- [x] 5.3 Add create or update regression coverage for duplicate bundle parent rows and per-parent bundle item ownership.
- [x] 5.4 Add financial regression coverage proving totals remain equivalent when duplicate rows include tax, discounts, shipping, or bundle totals.
- [x] 5.5 Run the targeted standard Sales and dispatch test suite and document any remaining failures.

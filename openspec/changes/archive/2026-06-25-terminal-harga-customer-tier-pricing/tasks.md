## 1. Test Coverage

- [x] 1.1 Add focused Terminal Harga Livewire/feature test setup for active setting, products, `product_prices`, and customers.
- [x] 1.2 Add coverage proving the default display uses active-setting `sale_price` and does not render tier price rows.
- [x] 1.3 Add coverage proving global customer search returns/selects a customer whose `setting_id` differs from the active setting.
- [x] 1.4 Add coverage for `WHOLESALER` using positive `tier_1_price` and falling back to `sale_price` when missing or non-positive.
- [x] 1.5 Add coverage for `RESELLER` using positive `tier_2_price` and falling back to `sale_price` when missing or non-positive.
- [x] 1.6 Add coverage proving customers without recognized tiers use `sale_price`.
- [x] 1.7 Add coverage proving clearing the selected customer restores `sale_price` display and customer changes reset pagination.

## 2. Livewire Component Behavior

- [x] 2.1 Add selected customer state to `App\Livewire\PricePoint\Browser` including selected id, label, tier, customer search text, and dropdown visibility/results as needed.
- [x] 2.2 Implement global customer search in `PricePoint\Browser` without filtering on `customers.setting_id`.
- [x] 2.3 Implement customer selection and clearing actions that resolve customer tier globally and reset the `pp` paginator.
- [x] 2.4 Implement a component-level contextual price resolver matching existing POS/Sales rules: default sale price, `WHOLESALER` tier 1 fallback sale price, `RESELLER` tier 2 fallback sale price.
- [x] 2.5 Preserve existing product search, active-setting `product_prices` filtering, conversion eager loading, and scanner refocus dispatch.

## 3. Terminal Harga UI

- [x] 3.1 Add a searchable customer dropdown/control to the Terminal Harga sticky header using the existing page styling.
- [x] 3.2 Show the selected customer name and tier context clearly, with a clear/remove action.
- [x] 3.3 Replace the product card multi-tier price list with one resolved contextual price and a concise price label.
- [x] 3.4 Preserve existing product metadata, image, code/barcode, brand/category, conversion, loading, empty state, and pagination layout.
- [x] 3.5 Ensure product search focus behavior remains scanner-friendly after product searches.

## 4. Verification

- [x] 4.1 Run the focused Terminal Harga tests added for this change.
- [x] 4.2 Run a focused related suite if practical for Livewire/product pricing regressions.
- [x] 4.3 Manually review the rendered Blade for no remaining all-tier product price list in Terminal Harga.
- [x] 4.4 Confirm OpenSpec validation/status reports the change as apply-ready.

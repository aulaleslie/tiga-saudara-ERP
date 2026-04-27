## 1. POS Cart Bundle Pricing

- [x] 1.1 Update POS bundle add-line pricing to initialize selected bundled parent rows from `product_bundles.bundle_sale_price`.
- [x] 1.2 Stop using legacy `product_bundles.price` as a POS bundle add-on for selected bundled rows.
- [x] 1.3 Preserve selected bundled row unit prices through POS customer selection changes and bypass customer tier repricing for bundled rows.
- [x] 1.4 Keep normal POS customer tier repricing unchanged for non-bundled rows.
- [x] 1.5 Carry bundle component informational price metadata internally for checkout allocation without rendering it as billable POS UI data.

## 2. Bundle Allocation Inputs

- [x] 2.1 Resolve component allocation amounts from `product_bundle_items.informational_item_price`.
- [x] 2.2 Add fallback resolution from each component product's active-setting `product_prices.sale_price` when informational price is missing.
- [x] 2.3 Ensure allocation fallback does not use legacy `product_bundle_items.price`.
- [x] 2.4 Detect negative parent residual cases during preflight/finalize and fail with an actionable validation error rather than posting negative revenue.
- [x] 2.5 Resolve parent/default tax candidate for bundle allocation using parent line tax first, then active/default sale tax, then no tax.

## 3. Split Planning

- [x] 3.1 Extend POS split planning to decompose selected bundled row gross amount into parent residual and component allocation parts.
- [x] 3.2 Assign parent residual revenue to parent stock allocation source groups.
- [x] 3.3 Assign stock-managed component allocation revenue to each component stock allocation source group.
- [x] 3.4 Assign non-stock-managed component allocation revenue to the first configured non-PKP sales-location source setting.
- [x] 3.5 Fail checkout preflight/finalize when a stockless component requires allocation but no configured non-PKP source setting exists.
- [x] 3.6 Use minor-unit arithmetic so parent residual plus component allocations exactly equals the customer-facing bundled row gross amount.

## 4. Posting And Persistence

- [x] 4.1 Update grouped posting payloads so generated POS split Sales documents receive owner-specific bundled revenue amounts.
- [x] 4.2 Preserve `sale_bundle_items` as non-billable composition context with zero billable `price` and `sub_total` values.
- [x] 4.3 Ensure dispatch details and stock movements continue to use parent/component resolver-selected source location, source setting, and tax bucket.
- [x] 4.4 Extract included tax from bundle allocation amounts only when the source owner is PKP and a parent/default tax candidate exists.
- [x] 4.5 Keep non-PKP bundle allocation amounts non-tax even when the parent/default tax candidate exists.
- [x] 4.6 Preserve split payment allocation and checkout split summary reconciliation after bundle revenue decomposition.

## 5. Regression Coverage

- [x] 5.1 Add POS cart coverage that selected bundle rows use `bundle_sale_price` and ignore legacy add-on price.
- [x] 5.2 Add POS cart coverage that bundled rows bypass customer tier repricing while non-bundled rows still reprice.
- [x] 5.3 Add POS split posting coverage for the POS 1 scenario: parent, mouse, and mousepad owned by three different settings produce owner-specific sale totals.
- [x] 5.4 Add POS split posting coverage for the POS 2 scenario: parent and one component share owner while another component differs, producing combined same-owner sale total.
- [x] 5.5 Add PKP coverage that bundle allocation tax is included-tax extracted from parent/default tax context for PKP source owners.
- [x] 5.6 Add stockless component coverage for allocation to the first configured non-PKP sales-location source setting.
- [x] 5.7 Add coverage that `sale_bundle_items` component rows remain non-billable after POS checkout posting.
- [x] 5.8 Run targeted POS bundle cart, POS checkout split posting, POS tax, and existing standard Sales bundle pricing tests.

# Proposal

## Why

Operators setting product sale prices need to anticipate global HPP after approved incoming purchases arrive. The current multi-business price page shows only current average purchase price and provides no direct way to inspect the incoming purchases behind a projection.

## What Changes

- Add a permanently read-only `Proyeksi HPP` base-unit column beside `Harga Beli Rata-rata`, showing one global projected value on every business row.
- Estimate receipt of all approved outstanding quantities across businesses, including unreceived remainders of partially received purchases, using current global stock and existing receipt valuation rules.
- Show current HPP when there are no outstanding purchases; explain the estimate through an Indonesian tooltip.
- Add a permission-aware `Lihat pembelian terkait ↗` link beside the column heading, opening the existing global purchase workspace in a new tab with this product's contributing purchases selected through durable filters.
- Verify through focused PHP/service/Livewire tests. Browser verification is performed only by a human.

## Capabilities

### New Capabilities

None.

### Modified Capabilities

- `cross-business-product-price-management`: Global projected HPP calculation, read-only presentation, and related-purchase navigation.
- `global-purchase-multi-payment`: Product/outstanding-purchase drilldown in the existing global workspace, preserving its permission and payment behavior.

## Impact

Affects the product cross-business controller/service/view, purchase global workspace and Livewire table/summary filtering, and reusable outstanding purchase queries. Reuses `PurchaseCostHelper` and existing approved-receipt eligibility rules. No schema changes, stored-price updates, or new dependencies are expected. Existing pricing save operations, receipt posting, and normal global-payment views retain their behavior.

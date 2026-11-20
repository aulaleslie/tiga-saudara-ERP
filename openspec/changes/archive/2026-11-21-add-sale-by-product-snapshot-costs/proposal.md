# Proposal

## Why

Users need to extract sales for ATK or other selected categories, brands, and products with both actual selling values and historical modal/HPP. Penjualan per produk currently omits snapshot costs and bundle components, making it unsuitable for comparing those values reliably.

## What Changes

- Extend Penjualan per produk with `Dijual sebagai`, `Total Modal`, and `Modal Rata-rata/unit`, retaining quantity, unit, sales total, and weighted average selling price.
- Add brand filtering, descendant category matching, and searchable checkbox multi-selection for businesses, categories, brands, and products, following Serial number terjual interaction patterns.
- Include bundle components and standalone bundle commercial rows; separate direct/parent sales from component sales of the same product.
- Use persisted POS component revenue and residual parent revenue without double-counting. Preserve non-POS included-component behavior and mark ambiguous historical selling values unavailable.
- Read historical HPP snapshots and provenance. Keep valid zero cost; mark incomplete totals partial and suppress average HPP for incomplete rows.
- Extend Excel/CSV with the same filters, fields, completeness labels, totals, and applied-filter guards as preview.
- Reconcile stale report specification wording about return columns and category all-match logic with the agreed sold-value report. Preserve current effective dates, fulfilled eligibility, settlement-modified values, and document-discount treatment.

## Capabilities

### New Capabilities

None.

### Modified Capabilities

- `sale-by-product-report`: Snapshot HPP presentation, bundle-aware product aggregates, shared master-data filters, checkbox selection, authorized multi-business scope, and matching exports.

## Impact

Affects `app/Livewire/Reports/SaleByProductReport.php`, its Blade view, filter DTO/validator/snapshot, query service, export, relevant report card description, and focused tests/documentation. Reuse business selection and product-selection patterns from SoldSerialNumberReport without changing that report or adopting its physical-return/original-date semantics.

No migrations, transaction mutations, historical backfills, new revenue allocation scheme, snapshot writer changes, or profit calculation. In particular, correcting future capture of genuine zero acquisition costs remains outside scope. Existing sales-report permissions remain in effect. POS, non-POS, and ambiguous historical bundle provenance receive separate read-side handling.

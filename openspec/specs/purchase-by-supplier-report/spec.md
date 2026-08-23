## Purpose

The Purchase by Supplier report provides a detailed supplier-grouped view of all purchase invoices within a date range and set of filters, supporting drill-down analysis of purchase behavior by supplier.

## Requirements

### Requirement: Purchase by supplier menu access
The system SHALL expose a `Pembelian Per Supplier` report under `Laporan -> Pembelian` for users with `purchaseReports.access`.

#### Scenario: Authorized user sees menu entry
- **WHEN** a user with `purchaseReports.access` opens the ERP sidebar
- **THEN** the `Laporan` menu contains a `Pembelian` submenu
- **AND** the submenu contains `Pembelian Per Supplier`

#### Scenario: Unauthorized user does not see menu entry
- **WHEN** a user without `purchaseReports.access` opens the ERP sidebar
- **THEN** the user does not see the `Pembelian Per Supplier` menu entry

#### Scenario: Authorized user opens report page
- **WHEN** a user with `purchaseReports.access` opens the `Pembelian Per Supplier` report route
- **THEN** the system displays the `Pembelian Per Supplier` page
- **AND** the report is scoped to the active setting

### Requirement: Purchase by supplier default date range
The system SHALL initialize `Tanggal awal` and `Tanggal akhir` to the current calendar month.

#### Scenario: Report opens with current month dates
- **WHEN** a user opens `Pembelian Per Supplier` on a given calendar date
- **THEN** `Tanggal awal` is set to the first day of that calendar month
- **AND** `Tanggal akhir` is set to the last day of that calendar month

### Requirement: View-only purchase by supplier report
The system SHALL provide the `Pembelian Per Supplier` report with active Excel and CSV export actions.

#### Scenario: Export Excel is available
- **WHEN** a user views `Pembelian Per Supplier` and has applied filters
- **THEN** the system provides an active Excel export action

#### Scenario: Export CSV is available
- **WHEN** a user views `Pembelian Per Supplier` and has applied filters
- **THEN** the system provides an active CSV export action

### Requirement: Purchase by supplier row grain
The system SHALL render `Faktur pembelian` purchase detail rows grouped by supplier.

#### Scenario: Purchase with multiple details appears as multiple rows in supplier group
- **WHEN** a purchase has three purchase detail rows and matches the report filters
- **THEN** the report displays three rows under that purchase supplier
- **AND** each row represents one purchase detail row

#### Scenario: Suppliers without matching purchases are hidden
- **WHEN** a supplier has no purchase detail rows matching the applied filters
- **THEN** the supplier is not displayed in the report

#### Scenario: Report is fixed to purchase invoices
- **WHEN** a user views `Pembelian Per Supplier`
- **THEN** displayed rows represent `Faktur pembelian`
- **AND** the report does not display purchase order rows
- **AND** the report does not display purchase quotation rows

### Requirement: Purchase by supplier columns
The system SHALL display the supplier-grouped table with sample-aligned Bahasa Indonesia columns.

#### Scenario: Report displays required columns
- **WHEN** a user runs the `Pembelian Per Supplier` report
- **THEN** the table includes `Supplier / Tanggal`
- **AND** the table includes `Tipe transaksi`
- **AND** the table includes `No. transaksi`
- **AND** the table includes `Nama produk`
- **AND** the table includes `Keterangan`
- **AND** the table includes `Qty`
- **AND** the table includes `Unit`
- **AND** the table includes `Harga per unit`
- **AND** the table includes `Nominal tagihan`
- **AND** the table includes `Total nominal tagihan`

#### Scenario: Keterangan uses purchase note
- **WHEN** a purchase detail row is displayed
- **THEN** the `Keterangan` column displays the related purchase note or memo when available
- **AND** the system displays an empty value or `-` when no purchase note or memo is available

### Requirement: Purchase by supplier monetary totals
The system SHALL use purchase detail `sub_total` for `Nominal tagihan` and compute `Total nominal tagihan` as a running total per supplier group.

#### Scenario: Nominal tagihan uses detail subtotal
- **WHEN** a purchase detail row has `sub_total` of 38880000
- **THEN** the row's `Nominal tagihan` is 38880000

#### Scenario: Total nominal tagihan is running supplier total
- **WHEN** a supplier group contains matching rows with `Nominal tagihan` values 38880000 and 60900000 in display order
- **THEN** the first row's `Total nominal tagihan` is 38880000
- **AND** the second row's `Total nominal tagihan` is 99780000

### Requirement: Purchase by supplier filters
The system SHALL provide filters for date range, period preset, supplier, tag, product category, tag/category matching logic, and sorting.

#### Scenario: User filters by date range
- **WHEN** a user applies `Tanggal awal` and `Tanggal akhir`
- **THEN** the report includes purchase detail rows whose purchase date is inside the selected date range
- **AND** the report excludes purchase detail rows whose purchase date is outside the selected date range

#### Scenario: Period preset updates pending date range
- **WHEN** a user selects a period preset such as `Bulan ini`
- **THEN** the pending `Tanggal awal` and `Tanggal akhir` values update to that preset range
- **AND** the displayed report rows do not change until the user applies the filter

#### Scenario: User filters by multiple suppliers
- **WHEN** a user selects multiple suppliers and applies the filters
- **THEN** the report includes purchase detail rows whose purchase supplier is any selected supplier
- **AND** the report excludes purchase detail rows from suppliers not selected

### Requirement: Purchase by supplier tag filter logic
The system SHALL support `Mencakup semua` and `Salah satu` matching logic for selected tags.

#### Scenario: Tag filter with Salah satu logic
- **WHEN** a user selects multiple tags, chooses `Salah satu`, and applies the filters
- **THEN** the report includes purchase detail rows whose purchase has at least one selected tag
- **AND** the report excludes purchase detail rows whose purchase has none of the selected tags

#### Scenario: Tag filter with Mencakup semua logic
- **WHEN** a user selects multiple tags, chooses `Mencakup semua`, and applies the filters
- **THEN** the report includes purchase detail rows whose purchase has every selected tag
- **AND** the report excludes purchase detail rows whose purchase is missing any selected tag

### Requirement: Purchase by supplier category filter logic
The system SHALL support `Mencakup semua` and `Salah satu` matching logic for selected product categories.

#### Scenario: Category filter with Salah satu logic
- **WHEN** a user selects multiple product categories, chooses `Salah satu`, and applies the filters
- **THEN** the report includes purchase detail rows whose product category matches any selected category
- **AND** the report excludes purchase detail rows whose product category matches none of the selected categories

#### Scenario: Category filter with Mencakup semua logic
- **WHEN** a user selects multiple product categories, chooses `Mencakup semua`, and applies the filters
- **THEN** the report includes purchase detail rows whose product category matches every selected category
- **AND** the report excludes purchase detail rows whose product category is missing any selected category

### Requirement: Purchase by supplier sorting and pagination
The system SHALL sort supplier report rows by transaction date descending within supplier groups and use normal row pagination.

#### Scenario: Rows inside supplier group are date descending
- **WHEN** a supplier has matching purchases dated 2026-05-09 and 2026-05-18
- **THEN** the 2026-05-18 row appears before the 2026-05-09 row within that supplier group

#### Scenario: User sorts by supplier
- **WHEN** a user selects `Supplier` sorting and applies the filters
- **THEN** supplier groups are ordered by supplier name according to the selected sort direction

#### Scenario: User sorts by total purchase
- **WHEN** a user selects `Total pembelian` sorting and applies the filters
- **THEN** supplier groups are ordered by the total purchase amount for each supplier according to the selected sort direction

#### Scenario: Report uses normal row pagination
- **WHEN** the filtered report has more rows than the page size
- **THEN** the system paginates by result rows
- **AND** a supplier group may continue on a later page

### Requirement: Purchase by supplier tax row expansion
The system SHALL render a separate `Pajak` row immediately after a purchase detail's product row when the persisted purchase detail has `product_tax_amount > 0`.

#### Scenario: Taxed purchase detail displays product and tax rows
- **WHEN** a matching purchase detail has `sub_total` 11162162.16 and `product_tax_amount` 1227837.84
- **THEN** the report displays a product row with `Nominal tagihan` 11162162.16
- **AND** the report displays a following row with `Nama produk` equal to `Pajak`
- **AND** the `Pajak` row has `Nominal tagihan` 1227837.84

#### Scenario: Untaxed purchase detail displays only product row
- **WHEN** a matching purchase detail has `product_tax_amount` 0
- **THEN** the report displays the purchase detail product row
- **AND** the report does not display a `Pajak` row for that detail

#### Scenario: Tax row uses persisted amount regardless of current PKP setting
- **WHEN** a matching purchase detail has `product_tax_amount` greater than 0
- **AND** the current setting has any `is_pkp` value
- **THEN** the report displays the `Pajak` row
- **AND** the report does not recompute tax from current tax settings

### Requirement: Purchase by supplier monetary totals (Tax)
The system SHALL use purchase detail `sub_total` for product-row `Nominal tagihan`, use purchase detail `product_tax_amount` for tax-row `Nominal tagihan`, and compute `Total nominal tagihan` as a running total per supplier group across all rendered product and tax rows.

#### Scenario: Product row nominal tagihan uses detail subtotal
- **WHEN** a purchase detail row has `sub_total` of 38880000
- **THEN** the product row's `Nominal tagihan` is 38880000

#### Scenario: Tax row nominal tagihan uses detail tax amount
- **WHEN** a purchase detail row has `product_tax_amount` of 4276800
- **THEN** the following `Pajak` row's `Nominal tagihan` is 4276800

#### Scenario: Total nominal tagihan includes tax rows in order
- **WHEN** a supplier group contains a product row with `Nominal tagihan` 11162162.16 followed by a `Pajak` row with `Nominal tagihan` 1227837.84
- **THEN** the product row's `Total nominal tagihan` is 11162162.16
- **AND** the `Pajak` row's `Total nominal tagihan` is 12390000.00

### Requirement: Purchase by supplier sorting and pagination
The system SHALL sort supplier report detail rows by transaction date descending within supplier groups and use normal detail-row pagination before tax-row expansion.

#### Scenario: Rows inside supplier group are date descending
- **WHEN** a supplier has matching purchases dated 2026-05-09 and 2026-05-18
- **THEN** the 2026-05-18 row appears before the 2026-05-09 row within that supplier group

#### Scenario: User sorts by supplier
- **WHEN** a user selects `Supplier` sorting and applies the filters
- **THEN** supplier groups are ordered by supplier name according to the selected sort direction

#### Scenario: User sorts by total purchase
- **WHEN** a user selects `Total pembelian` sorting and applies the filters
- **THEN** supplier groups are ordered by the total purchase amount for each supplier according to the selected sort direction
- **AND** each supplier total used for ordering includes matching purchase detail `sub_total` and `product_tax_amount`

#### Scenario: Report uses normal detail-row pagination
- **WHEN** the filtered report has more detail rows than the page size
- **THEN** the system paginates by matching purchase detail rows
- **AND** a supplier group may continue on a later page
- **AND** a displayed page may contain more rendered table rows than the page size when tax rows are present

#### Scenario: Running total carries tax amounts across pages
- **WHEN** prior pages contain purchase details with `sub_total` and `product_tax_amount`
- **THEN** the first row on the next page continues from the sum of prior-page product and tax amounts for that supplier

### Requirement: Document discount row in supplier expansion

The report SHALL emit exactly one document `Diskon` row per purchase invoice whose `Purchase.discount_amount` is greater than zero, placed after that invoice's product detail rows and before its `Pajak` row within the supplier group, with `Nama produk` set to `Diskon` and `Nominal tagihan` set to the negative of the document discount amount. The discount SHALL reduce the running `Total nominal tagihan` so the invoice's rows reconcile to the document total. Both the on-screen rows and the exported rows SHALL include this discount row identically.

#### Scenario: Discounted purchase expands with a discount row

- **WHEN** a purchase has a positive `discount_amount` and matches the report filters
- **THEN** the supplier group shows the purchase's product/DPP rows, then a `Diskon` row whose `Nominal tagihan` is the negative document discount, then any `Pajak` row
- **AND** the `Total nominal tagihan` for that purchase reconciles to the purchase total

#### Scenario: Discount row appears once for a multi-line purchase

- **WHEN** a purchase has three detail rows and a single positive `discount_amount`
- **THEN** the report shows the three product/DPP rows followed by exactly one `Diskon` row for the purchase

#### Scenario: No discount row when the purchase has no discount

- **WHEN** a purchase has `discount_amount` of 0
- **THEN** the report shows no `Diskon` row for that purchase

#### Scenario: Export matches on-screen discount row

- **WHEN** a discounted purchase is exported
- **THEN** the exported rows contain the same `Diskon` row, in the same position, as the on-screen report

### Requirement: Purchase by supplier excludes archived purchases
The system SHALL exclude purchase details whose parent purchase is archived from the normal Purchase by Supplier dataset. The exclusion MUST apply consistently to on-screen rows, filtered result counts, pagination, sorting, running totals, grand totals, and Excel and CSV exports.

#### Scenario: Archived purchase matches the active filters
- **WHEN** an archived purchase belongs to the active setting and its effective purchase date, supplier, tags, and product categories match the applied filters
- **THEN** none of its purchase detail rows are included in the Purchase by Supplier report
- **AND** applying the filters and rendering the report completes without an error

#### Scenario: Active and archived purchases match together
- **WHEN** an active purchase and an archived purchase both belong to the active setting and match the applied filters
- **THEN** the report includes the active purchase detail rows
- **AND** the report excludes the archived purchase detail rows
- **AND** result counts, pagination, sorting, running totals, and grand totals are calculated only from the active purchase detail rows

#### Scenario: Export uses the same non-archived dataset
- **WHEN** a user exports applied filters that match both active and archived purchases
- **THEN** the Excel or CSV export contains the matching active purchase rows
- **AND** the export does not contain rows or monetary contributions from the archived purchase


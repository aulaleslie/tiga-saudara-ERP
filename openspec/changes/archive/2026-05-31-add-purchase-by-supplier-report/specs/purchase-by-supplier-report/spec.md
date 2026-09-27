## ADDED Requirements

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
The system SHALL provide the `Pembelian Per Supplier` report as a view-only report without active export behavior.

#### Scenario: Export is not available
- **WHEN** a user views `Pembelian Per Supplier`
- **THEN** the system does not provide an active Excel export action
- **AND** the system does not provide an active CSV export action
- **AND** the system does not provide an active PDF export action

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

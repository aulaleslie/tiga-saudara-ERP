## ADDED Requirements

### Requirement: Purchase by product report entry point
The system SHALL provide a `Pembelian per produk` report reachable from the Reports module and gated by `purchaseReports.access`.

#### Scenario: Authorized user opens the report
- **WHEN** a user with `purchaseReports.access` requests the purchase by product report route
- **THEN** the system displays the `Pembelian per produk` report page

#### Scenario: Unauthorized user is denied
- **WHEN** a user without `purchaseReports.access` requests the purchase by product report route
- **THEN** the system returns 403

#### Scenario: Reports landing card is actionable
- **WHEN** a user with `purchaseReports.access` views the `Pembelian` tab on the Reports landing page
- **THEN** the `Pembelian per produk` card links to the purchase by product report route
- **AND** the card does not show placeholder or unavailable-state treatment

### Requirement: Purchase invoice details are aggregated by product
The report SHALL calculate purchase quantity and purchase value from existing `purchases` and `purchase_details` records, scoped to the active setting and filtered by `purchases.date`.

#### Scenario: Purchase inside selected period is included
- **WHEN** a purchase invoice has a purchase date inside the selected report period
- **THEN** its purchase detail quantities and purchase values are included in the product aggregate

#### Scenario: Purchase outside selected period is excluded
- **WHEN** a purchase invoice has a purchase date outside the selected report period
- **THEN** its purchase detail quantities and purchase values are not included in the product aggregate

#### Scenario: Purchases are scoped to active setting
- **WHEN** another setting has purchase details in the selected period
- **THEN** those purchase details are not included in the active setting report

#### Scenario: Purchase order and quotation rows are excluded
- **WHEN** non-invoice purchase order or purchase quotation data exists in the system
- **THEN** the purchase by product report does not include those rows in first scope

### Requirement: Lifecycle-valid purchase returns are aggregated by product
The report SHALL calculate return quantity and return value from existing `purchase_returns` and `purchase_return_details` records, scoped to the active setting, filtered by `purchase_returns.date`, and limited to purchase returns that have progressed into actual return execution or settlement after approval.

#### Scenario: Executed return inside selected period is included
- **WHEN** a purchase return is approved and has progressed into return execution or settlement
- **AND** its return date is inside the selected report period
- **THEN** its return detail quantities and return values are included in the product aggregate

#### Scenario: Draft pending or rejected return is excluded
- **WHEN** a purchase return is draft, pending approval, or rejected
- **THEN** its return detail quantities and return values are not included in the product aggregate

#### Scenario: Approved but not dispatched return is excluded
- **WHEN** a purchase return is approved but has not progressed into return execution or settlement
- **THEN** its return detail quantities and return values are not included in the product aggregate

#### Scenario: Return date controls return inclusion
- **WHEN** the source purchase date is outside the selected period
- **AND** a lifecycle-valid purchase return date is inside the selected period
- **THEN** the return detail quantities and return values are included in the report

### Requirement: Tax-exclusive purchase value calculation
The report SHALL calculate `Nilai pembelian` and `Nilai retur` as tax-exclusive line commercial values. For tax-included purchase lines, the report MUST subtract the persisted line tax amount from the line subtotal before aggregation.

#### Scenario: Tax-included purchase value excludes tax
- **WHEN** a tax-included purchase line has subtotal 111,000 and product tax amount 11,000
- **THEN** the report contributes 100,000 to `Nilai pembelian`

#### Scenario: Tax-exclusive purchase value uses subtotal
- **WHEN** a tax-exclusive purchase line has subtotal 100,000 and product tax amount 11,000
- **THEN** the report contributes 100,000 to `Nilai pembelian`

#### Scenario: Average purchase value is zero-safe
- **WHEN** a product row has zero purchased quantity
- **THEN** `Nilai pembelian rata-rata` is shown as zero instead of causing a division error

#### Scenario: Return value uses persisted return amount when source tax context is unavailable
- **WHEN** a lifecycle-valid purchase return detail cannot resolve its source purchase tax-inclusion context
- **THEN** the report uses the persisted return detail value without recomputing tax from current settings

### Requirement: Product aggregate presentation
The report SHALL present one aggregate row per product and unit combination with columns for product code, product name, purchase quantity, return quantity, unit, purchase value, return value, and average purchase value.

#### Scenario: Product row displays aggregate columns
- **WHEN** a product has matching purchased or returned quantities
- **THEN** the row displays `Kode produk / SKU`, `Nama produk`, `Qty pembelian`, `Qty retur`, `Unit`, `Nilai pembelian`, `Nilai retur`, and `Nilai pembelian rata-rata`

#### Scenario: Product without code remains reportable
- **WHEN** a matching purchase or return detail has no product code
- **THEN** the report still includes the product row with a blank or fallback code display

#### Scenario: Total row is shown
- **WHEN** the report has one or more matching product rows
- **THEN** the report shows a total row summing `Nilai pembelian` and `Nilai retur`

#### Scenario: Empty result state is shown
- **WHEN** filters match no purchase details and no lifecycle-valid purchase return details
- **THEN** the report shows an empty state instead of totals

### Requirement: Purchase by product filtering and sorting
The report SHALL support date range, period presets, supplier, tag, product category, and product filters, with configurable tag and category match logic, and sorting by product name, product code, purchase quantity, return quantity, purchase value, and average purchase value.

#### Scenario: Supplier filter narrows rows
- **WHEN** the user selects one or more suppliers and applies filters
- **THEN** only purchases and lifecycle-valid returns for those suppliers are included

#### Scenario: Tag all-match logic
- **WHEN** the user selects multiple tags with `Mencakup semua`
- **THEN** only purchases containing every selected tag are included

#### Scenario: Category any-match logic
- **WHEN** the user selects multiple product categories with `Salah satu`
- **THEN** product rows in at least one selected category are included

#### Scenario: Product filter narrows rows
- **WHEN** the user selects one or more products and applies filters
- **THEN** only rows for those selected products are included

#### Scenario: Sort by return quantity
- **WHEN** the user sorts by return quantity
- **THEN** rows are ordered by `Qty retur` in the selected direction with deterministic fallback ordering

#### Scenario: Period presets update date range
- **WHEN** the user selects a period preset such as current month or previous month
- **THEN** the pending `Tanggal awal` and `Tanggal akhir` values reflect that period before filters are applied

### Requirement: Snapshot-validated exports
The report SHALL export Excel and CSV only when the current filters match the last applied filter snapshot. Exported rows and totals MUST match the on-screen report for the same applied filters.

#### Scenario: Export blocked before applying filters
- **WHEN** the user attempts to export before applying filters
- **THEN** the system refuses the export and asks the user to apply filters first

#### Scenario: Export matches current report rows
- **WHEN** the user exports after applying filters
- **THEN** the exported product rows and totals match the report data for those filters

#### Scenario: Export blocked after filter changes
- **WHEN** the user changes filters after applying them
- **THEN** the system refuses export until the filters are applied again

#### Scenario: XLSX includes report metadata
- **WHEN** the user exports Excel
- **THEN** the XLSX includes company name, report title `Pembelian dengan Produk`, selected date range, and `(dalam IDR)` metadata rows above the table

#### Scenario: CSV omits report metadata
- **WHEN** the user exports CSV
- **THEN** the CSV contains the table headings and data rows without the XLSX metadata rows

### Requirement: First-scope exclusions
The report SHALL NOT implement PDF export, purchase quotation/order transaction sources, or the detailed transaction-number/discount report mode in this change.

#### Scenario: PDF is not implemented
- **WHEN** the export controls are rendered for the first-scope report
- **THEN** PDF export is absent or unavailable

#### Scenario: Transaction type expansion is not applied
- **WHEN** the report calculates product aggregates
- **THEN** it uses purchase invoice data and does not include purchase quotation or purchase order rows

#### Scenario: Detailed mode is not implemented
- **WHEN** the report is rendered
- **THEN** it does not provide the `Lihat versi lebih detail` transaction-number/discount mode

### Requirement: No schema changes
The purchase by product report SHALL be implemented without adding database migrations or changing existing transaction lifecycle behavior.

#### Scenario: Implementation is read-only against transaction schema
- **WHEN** the change is implemented
- **THEN** no new database migration is required for the report

#### Scenario: Existing workflows are preserved
- **WHEN** users create purchases, receive purchases, approve purchase returns, dispatch purchase returns, settle returns, or export other reports
- **THEN** those existing workflows behave as before

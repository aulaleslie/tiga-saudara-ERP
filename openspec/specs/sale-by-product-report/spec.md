## ADDED Requirements

### Requirement: Sales by product report entry point
The system SHALL provide a "Penjualan per produk" report reachable from the Reports module and gated by `saleReports.access`.

#### Scenario: Authorized user opens the report
- **WHEN** a user with `saleReports.access` requests the sales by product report route
- **THEN** the system displays the "Penjualan per produk" report page

#### Scenario: Unauthorized user is denied
- **WHEN** a user without `saleReports.access` requests the sales by product report route
- **THEN** the system returns 403

### Requirement: Invoice sales are aggregated by product
The report SHALL calculate sold quantity and sold value from existing `sales` and `sale_details` records, scoped to the selected company scope via `sales.setting_id`, and filtered by `sales.date`. When no company scope is selected, the scope SHALL default to the current setting.

#### Scenario: Sale inside selected period is included
- **WHEN** a sale invoice has a sale date inside the selected report period
- **THEN** its sale detail quantities and sales values are included in the product aggregate

#### Scenario: Sale outside selected period is excluded
- **WHEN** a sale invoice has a sale date outside the selected report period
- **THEN** its sale detail quantities and sales values are not included in the product aggregate

#### Scenario: Sales are scoped to the selected settings
- **WHEN** a setting outside the selected company scope has sale details in the selected period
- **THEN** those sale details are not included in the report

#### Scenario: Sales across selected settings are combined
- **WHEN** two settings are both within the selected company scope and each has sale details for the same product in the selected period
- **THEN** those sale details are combined into a single product aggregate row

### Requirement: Received sales returns are aggregated by product
The report SHALL calculate return quantity and return value from existing `sale_returns` and `sale_return_details` records, scoped to the selected company scope via `sale_returns.setting_id`, filtered by `sale_returns.date`, and limited to received return statuses `Awaiting Settlement` and `Completed` using case-insensitive comparison.

#### Scenario: Received return inside selected period is included
- **WHEN** a sales return has status `Awaiting Settlement` or `Completed`
- **AND** its return date is inside the selected report period
- **THEN** its return detail quantities and return values are included in the product aggregate

#### Scenario: Unreceived return is excluded
- **WHEN** a sales return is pending approval, rejected, deleted, archived before receiving, or awaiting receiving
- **THEN** its return detail quantities and return values are not included in the product aggregate

#### Scenario: Return date controls return inclusion
- **WHEN** the source sale date is outside the selected period
- **AND** the received return date is inside the selected period
- **THEN** the return detail quantities and return values are included in the report

### Requirement: Tax-exclusive value calculation
The report SHALL calculate `Total Nilai terjual` and `Total Nilai Retur` as tax-exclusive line commercial values. For tax-included sale lines, the report MUST subtract the line tax amount from the line subtotal before aggregation.

#### Scenario: Tax-included sale value excludes tax
- **WHEN** a tax-included sale line has subtotal 111,000 and product tax amount 11,000
- **THEN** the report contributes 100,000 to `Total Nilai terjual`

#### Scenario: Tax-exclusive sale value uses subtotal
- **WHEN** a tax-exclusive sale line has subtotal 100,000 and product tax amount 11,000
- **THEN** the report contributes 100,000 to `Total Nilai terjual`

#### Scenario: Average sales price is zero-safe
- **WHEN** a product row has zero sold quantity
- **THEN** `Harga Penjualan Rata-rata` is shown as zero instead of causing a division error

### Requirement: Product aggregate presentation
The report SHALL present one aggregate row per product and unit combination with columns for product code, product name, sold quantity, return quantity, unit, sold value, return value, and average sales price.

#### Scenario: Product row displays aggregate columns
- **WHEN** a product has matching sold or returned quantities
- **THEN** the row displays `Kode Produk`, `Nama Produk`, `Kuantitas Terjual`, `Kuantitas Retur`, `Satuan`, `Total Nilai terjual`, `Total Nilai Retur`, and `Harga Penjualan Rata-rata`

#### Scenario: Product without code remains reportable
- **WHEN** a matching sale or return detail has no product code
- **THEN** the report still includes the product row with a blank or fallback code display

#### Scenario: Total row is shown
- **WHEN** the report has one or more matching product rows
- **THEN** the report shows a total row summing `Total Nilai terjual` and `Total Nilai Retur`

#### Scenario: Empty result state is shown
- **WHEN** filters match no sale details and no received return details
- **THEN** the report shows an empty state instead of totals

### Requirement: Report filtering and sorting
The report SHALL support date range, period presets, company scope, customer, tag, product category, and product filters, with configurable tag and category match logic, and sorting by product name, product code, sold quantity, return quantity, sales value, and average sales value.

Filter option lookups for products, product categories, and customers SHALL NOT be scoped by `setting_id`. Master data records `setting_id` as the setting in which the record was created, not the business that owns it, so scoping option lookups by it would offer a set of options that disagrees with the rows the report can return. Only transaction records (`sales`, `sale_returns`) SHALL be scoped by setting.

Filter option lookups SHALL match search input by splitting it on whitespace and requiring every resulting token to match, rather than matching the input as a single literal phrase. Each token MAY match any of the fields searched by that lookup. The product lookup SHALL search both `product_name` and `product_code`, and SHALL present the product code alongside the product name so that products with similar names remain distinguishable.

#### Scenario: Customer filter narrows rows
- **WHEN** the user selects one or more customers and applies filters
- **THEN** only sales and returns for those customers are included

#### Scenario: Tag all-match logic
- **WHEN** the user selects multiple tags with "Mencakup semua"
- **THEN** only sales containing every selected tag are included

#### Scenario: Category any-match logic
- **WHEN** the user selects multiple product categories with "Salah satu"
- **THEN** product rows in at least one selected category are included

#### Scenario: Product filter narrows rows
- **WHEN** the user selects one or more products and applies filters
- **THEN** only rows for those selected products are included

#### Scenario: Sort by return quantity
- **WHEN** the user sorts by return quantity
- **THEN** rows are ordered by `Kuantitas Retur` in the selected direction with deterministic fallback ordering

#### Scenario: Product search finds a product created in another setting
- **WHEN** a sale in the selected company scope references a product whose `products.setting_id` differs from that of the sale
- **AND** the user searches for that product by name in the product filter
- **THEN** the product SHALL be offered as a selectable option
- **AND** selecting it SHALL narrow the report to that product's rows

#### Scenario: Category search is not restricted by setting
- **WHEN** the user searches product categories in the filter panel
- **THEN** matching categories SHALL be offered regardless of the setting in which they were created

#### Scenario: Duplicate category names are listed separately
- **WHEN** more than one category record shares the same name across settings
- **THEN** each matching category SHALL be listed as its own option
- **AND** the options SHALL NOT be merged or deduplicated by name

#### Scenario: Customer search is not restricted by setting
- **WHEN** the user searches customers in the filter panel
- **THEN** matching customers SHALL be offered regardless of the setting recorded on the customer record

#### Scenario: Partial words across tokens match a product
- **WHEN** the user enters a search term whose whitespace-separated tokens each appear in a product's name but which does not appear as a single contiguous phrase
- **THEN** that product SHALL be offered as a selectable option

#### Scenario: Every token must match
- **WHEN** the user enters a search term in which one token matches no searched field of a product
- **THEN** that product SHALL NOT be offered as a selectable option

#### Scenario: Token order does not affect matching
- **WHEN** the user enters the same set of tokens in a different order
- **THEN** the same set of products SHALL be offered

#### Scenario: Product code is searchable
- **WHEN** the user enters a term matching a product's `product_code`
- **THEN** that product SHALL be offered as a selectable option

#### Scenario: Product options display the product code
- **WHEN** product options are presented in the filter panel
- **THEN** each option SHALL display the product code together with the product name

#### Scenario: Tokens may match across different searched fields
- **WHEN** the user enters a search term in which one token matches a product's name and another matches that product's code
- **THEN** that product SHALL be offered as a selectable option

#### Scenario: Minimum search length still applies
- **WHEN** the user enters a search term shorter than the minimum search length
- **THEN** no options SHALL be offered

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
- **THEN** the XLSX includes company name, report title `Penjualan dengan Produk`, selected date range, and `(dalam IDR)` metadata rows above the table

### Requirement: First-scope exclusions
The report SHALL NOT implement PDF export, quotation/order transaction sources, or the detailed transaction-number/discount report mode in this change.

#### Scenario: PDF is not implemented
- **WHEN** the export controls are rendered for the first-scope report
- **THEN** PDF export is absent or unavailable

#### Scenario: Transaction type expansion is not applied
- **WHEN** the report calculates product aggregates
- **THEN** it uses sales invoice data and does not include quotation or sales-order rows

#### Scenario: Detailed mode is not implemented
- **WHEN** the report is rendered
- **THEN** it does not provide the "Lihat versi lebih detail" transaction-number/discount mode

### Requirement: No schema changes
The sales by product report SHALL be implemented without adding database migrations or changing existing transaction lifecycle behavior.

#### Scenario: Implementation is read-only against transaction schema
- **WHEN** the change is implemented
- **THEN** no new database migration is required for the report

#### Scenario: Existing workflows are preserved
- **WHEN** users create sales, receive returns, settle returns, or export other reports
- **THEN** those existing workflows behave as before

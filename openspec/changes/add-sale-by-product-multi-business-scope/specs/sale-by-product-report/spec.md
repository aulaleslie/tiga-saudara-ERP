## MODIFIED Requirements

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

### Requirement: Report filtering and sorting
The report SHALL support date range, period presets, company scope, customer, tag, product category, and product filters, with configurable tag and category match logic, and sorting by product name, product code, sold quantity, return quantity, sales value, and average sales value.

Filter option lookups for products, product categories, and customers SHALL NOT be scoped by `setting_id`. Master data records `setting_id` as the setting in which the record was created, not the business that owns it, so scoping option lookups by it would offer a set of options that disagrees with the rows the report can return. Only transaction records (`sales`, `sale_returns`) SHALL be scoped by setting.

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

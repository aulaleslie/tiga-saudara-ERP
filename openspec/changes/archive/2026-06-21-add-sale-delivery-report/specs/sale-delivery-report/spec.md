## ADDED Requirements

### Requirement: Sales delivery report entry point
The system SHALL provide a "Pengiriman penjualan" report reachable from the Reports module and gated by `saleReports.access`.

#### Scenario: Authorized user opens the report
- **WHEN** a user with `saleReports.access` requests the sales delivery report route
- **THEN** the system displays the "Pengiriman penjualan" report page

#### Scenario: Unauthorized user is denied
- **WHEN** a user without `saleReports.access` requests the sales delivery report route
- **THEN** the system returns 403

### Requirement: Delivery rows are sourced from approved dispatches
The report SHALL calculate delivered quantities from existing `dispatches` and `dispatch_details` records, using only approved dispatches and filtering by `dispatches.dispatch_date`.

#### Scenario: Approved dispatch included
- **WHEN** an approved dispatch has a dispatch date inside the selected report period
- **THEN** its dispatch detail quantities are included in the report

#### Scenario: Pending and rejected dispatches excluded
- **WHEN** a pending or rejected dispatch has a dispatch date inside the selected report period
- **THEN** its dispatch detail quantities are not included in the report

#### Scenario: Sale date does not control inclusion
- **WHEN** a sale date is outside the selected period but its approved dispatch date is inside the selected period
- **THEN** the dispatch detail quantities are included in the report

### Requirement: Composite dispatch key calculation
The report SHALL aggregate and join delivery rows using the existing dispatch composite key: `sale_id`, `product_id`, normalized `tax_id`, and normalized `bundle_id`.

#### Scenario: Same product with different tax context remains separate
- **WHEN** the same sale dispatches the same product with different `tax_id` values
- **THEN** the report keeps those delivery rows separate for quantity and amount calculation

#### Scenario: Same product as standalone and bundle component remains separate
- **WHEN** the same sale dispatches the same product as a standalone item and as a bundle component
- **THEN** the report keeps those delivery rows separate by `bundle_id`

#### Scenario: Dispatch rows without sale detail id are reportable
- **WHEN** dispatch details do not contain `sale_detail_id`
- **THEN** the report still includes them using the composite key

### Requirement: Delivery amount calculation
The report SHALL calculate `Jumlah` by matching delivered quantities to commercial sale aggregates using the same composite key.

#### Scenario: Standard sale detail amount is prorated by delivered quantity
- **WHEN** a sale line has ordered quantity 10 and commercial amount 1,000,000
- **AND** an approved dispatch delivers quantity 4 for the same composite key
- **THEN** the report shows quantity 4 and amount 400,000

#### Scenario: Multiple sale details with same composite key do not double count
- **WHEN** multiple sale detail rows share the same sale, product, tax, and bundle context
- **THEN** the report aggregates their quantity and amount before joining to delivered quantity

#### Scenario: Bundle component uses persisted component amount
- **WHEN** a bundle component has a persisted commercial amount
- **THEN** the report uses that amount for delivery amount calculation

#### Scenario: Zero-value bundle component remains zero
- **WHEN** a bundle component has zero persisted commercial amount
- **THEN** the report shows delivered quantity and zero amount for that component

### Requirement: Report filtering and sorting
The report SHALL support date range, period presets, customer, tag, and product category filters, with configurable tag and category match logic, and sorting by customer, delivery, or product.

#### Scenario: Customer filter narrows rows
- **WHEN** the user selects one or more customers and applies filters
- **THEN** only dispatch rows for sales belonging to those customers are shown

#### Scenario: Tag all-match logic
- **WHEN** the user selects multiple tags with "Mencakup semua"
- **THEN** only sales containing every selected tag are included

#### Scenario: Category any-match logic
- **WHEN** the user selects multiple product categories with "Salah satu"
- **THEN** dispatch rows for products in at least one selected category are included

#### Scenario: Sort by product
- **WHEN** the user sorts by product
- **THEN** rows are ordered by product display name within the report's grouped output

### Requirement: Grouped customer presentation
The report SHALL present rows grouped by customer with product/SKU, product name, unit, quantity, amount, customer subtotal, and grand total.

#### Scenario: Customer subtotal is shown
- **WHEN** a customer group has one or more matching delivery rows
- **THEN** the report shows a subtotal equal to that customer's row amounts

#### Scenario: Grand total is shown
- **WHEN** the report has one or more matching delivery rows
- **THEN** the report shows a grand total equal to all customer subtotals

#### Scenario: Empty result state is shown
- **WHEN** filters match no approved dispatch details
- **THEN** the report shows an empty state instead of totals

### Requirement: Snapshot-validated exports
The report SHALL export Excel and CSV only when the current filters match the last applied filter snapshot.

#### Scenario: Export blocked before applying filters
- **WHEN** the user attempts to export before applying filters
- **THEN** the system refuses the export and asks the user to apply filters first

#### Scenario: Export matches current report rows
- **WHEN** the user exports after applying filters
- **THEN** the exported rows, customer subtotals, and grand total match the report data for those filters

#### Scenario: Export blocked after filter changes
- **WHEN** the user changes filters after applying them
- **THEN** the system refuses export until the filters are applied again

### Requirement: No schema dependency on sale detail id
The sales delivery report SHALL NOT require a new migration or depend on `dispatch_details.sale_detail_id`.

#### Scenario: Import-created dispatch details are included
- **WHEN** dispatch details were created by sales import without `sale_detail_id`
- **THEN** the report includes them when they match the selected filters

#### Scenario: Implementation is read-only against schema
- **WHEN** the change is implemented
- **THEN** no new database migration is required for the report

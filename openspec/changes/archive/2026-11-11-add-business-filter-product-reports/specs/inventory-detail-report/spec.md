## MODIFIED Requirements

### Requirement: Inventory detail report access

The system SHALL provide a `Detail persediaan barang` report at the route `reports.inventory-detail-report.index`, gated by the `stockMutationReports.access` permission, scoped to the businesses selected through the shared report business scope (default: the user's active setting).

#### Scenario: Permitted user opens the report

- **WHEN** a user holding `stockMutationReports.access` requests `reports.inventory-detail-report.index`
- **THEN** the system renders the `Detail persediaan barang` report with the user's active setting selected
- **AND** the page title is `Detail persediaan barang`
- **AND** the page displays the `(dalam IDR)` note for parity with the provided sample

#### Scenario: Unpermitted user is denied

- **WHEN** a user without `stockMutationReports.access` requests `reports.inventory-detail-report.index`
- **THEN** the system denies access

#### Scenario: Data is scoped to the active setting

- **WHEN** the report renders
- **THEN** only stock-managed products with inventory transactions at locations of the selected businesses are included
- **AND** products are included regardless of their owning business

## ADDED Requirements

### Requirement: Detail ledger is replayed per business
When several businesses are selected, the opening balance, running stock, ledger rows and subtotal SHALL be computed separately for each business. The expanded product ledger SHALL be grouped by business. The product summary SHALL show Total stock plus stock per business. Exports SHALL include the business grouping.

#### Scenario: Expanded product with two businesses
- **WHEN** A and B are selected and the user expands a product with activity in both
- **THEN** the ledger SHALL show separate A and B groups, each with its own Saldo Awal, rows and subtotal

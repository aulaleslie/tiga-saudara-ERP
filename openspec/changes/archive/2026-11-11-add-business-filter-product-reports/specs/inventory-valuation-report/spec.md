## MODIFIED Requirements

### Requirement: Inventory valuation report access

The system SHALL provide an Inventory Valuation ("Nilai Persediaan Barang") report at the route `reports.inventory-valuation-report.index`, gated by the `inventoryValuationReports.access` permission, scoped to the businesses selected through the shared report business scope (default: the user's active setting).

#### Scenario: Permitted user opens the report

- **WHEN** a user holding `inventoryValuationReports.access` requests `reports.inventory-valuation-report.index`
- **THEN** the system renders the Inventory Valuation report with the user's active setting selected
- **AND** the page title is "Nilai Persediaan Barang" with a "(dalam IDR)" currency note

#### Scenario: Unpermitted user is denied

- **WHEN** a user without `inventoryValuationReports.access` requests `reports.inventory-valuation-report.index`
- **THEN** the system denies access

#### Scenario: Data is scoped to the active setting

- **WHEN** the report renders
- **THEN** only inventory transactions at locations of the selected businesses are included
- **AND** products are included regardless of their owning business

## ADDED Requirements

### Requirement: Valuation ledger is replayed per business
When several businesses are selected, the opening balance, running stock, weighted-average cost, ledger rows and subtotal SHALL be computed separately for each business. The expanded product ledger SHALL be grouped by business, with a `Bisnis` label for each group. Movements from different businesses SHALL never share a running balance.

#### Scenario: Expanded product with two businesses
- **WHEN** A and B are selected and the user expands a product with activity in both
- **THEN** the ledger SHALL show an A group (Saldo Awal, A's rows, A's subtotal) and a B group (Saldo Awal, B's rows, B's subtotal)

### Requirement: Valuation product summary shows total and per-business values
With several businesses selected, each product summary row SHALL show the Total closing stock and value plus each business's closing stock and value. The grand total SHALL equal the sum of the business values. Exports SHALL include the business grouping.

#### Scenario: Grand total across businesses
- **WHEN** A's closing value is 1,000 and B's is 500 for the filtered products
- **THEN** the grand total value SHALL be 1,500

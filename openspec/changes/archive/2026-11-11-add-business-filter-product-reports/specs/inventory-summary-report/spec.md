# Spec Delta

## Purpose

Ringkasan persediaan barang shows on-hand stock, average cost and value per product as of a date, for one or more businesses side by side.

## ADDED Requirements

### Requirement: Inventory summary report access
The system SHALL provide `Ringkasan persediaan barang` at `reports.inventory-summary-report.index`, gated by `inventoryValuationReports.access`, and scoped by the shared report business scope.

#### Scenario: Unpermitted user is denied
- **WHEN** a user without `inventoryValuationReports.access` requests the route
- **THEN** the system SHALL deny access

### Requirement: Inventory summary matrix layout
With one business selected, the report SHALL show product code, product name, on-hand stock, minimum stock, unit, average cost and value. With several selected, it SHALL show one row per product, a `Total` group (stock, value), and one group per selected business (stock, average cost, value).

#### Scenario: Two businesses selected
- **WHEN** A and B are selected
- **THEN** each product row SHALL show Total stock and value, plus A's and B's stock, average cost and value

#### Scenario: Grand totals
- **WHEN** several businesses are selected
- **THEN** the footer SHALL show a total value for each business and a grand total equal to their sum

### Requirement: Inventory summary sorting and export
Sorting by stock or value SHALL use the Total group. CSV and XLSX exports SHALL contain the same column groups and totals as the screen.

#### Scenario: Sort by value
- **WHEN** the user sorts by value with A and B selected
- **THEN** rows SHALL be ordered by total value across A and B

#### Scenario: Export matches screen
- **WHEN** the user exports with A and B selected
- **THEN** the file SHALL contain the Total and per-business columns and the totals

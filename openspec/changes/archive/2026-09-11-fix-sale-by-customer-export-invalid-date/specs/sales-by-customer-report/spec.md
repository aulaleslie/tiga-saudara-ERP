## ADDED Requirements

### Requirement: Archived sales are excluded from sales by customer reporting

The system SHALL exclude archived sales and their detail rows from Penjualan Per Customer screen results, customer totals, pagination, XLSX exports, and CSV exports, even when the report query joins the sales table directly.

#### Scenario: Archived matching sale is omitted

- **WHEN** a sale matches the active setting, date range, and selected report filters but has a non-null `archived_at`
- **THEN** its detail rows MUST NOT appear in the Penjualan Per Customer screen results
- **AND** its amounts MUST NOT contribute to customer totals or the grand total
- **AND** its detail rows MUST NOT appear in XLSX or CSV exports

#### Scenario: Active matching sale remains included

- **WHEN** a non-archived sale matches the active setting, date range, and selected report filters
- **THEN** its detail rows SHALL remain available to the Penjualan Per Customer screen and exports

### Requirement: Sales by customer exports handle effective dates safely

The system SHALL export each included sale row using the same effective sale reporting date used by report filtering and ordering. XLSX and CSV generation MUST NOT fail with a date parsing exception when an export row has no parseable date value.

#### Scenario: Reporting-date override is exported

- **WHEN** an included active sale has a reporting-date override
- **THEN** each exported row for that sale SHALL contain the override formatted as `d/m/Y`

#### Scenario: Original date is exported without an override

- **WHEN** an included active sale has no reporting-date override
- **THEN** each exported row for that sale SHALL contain its original sale date formatted as `d/m/Y`

#### Scenario: Missing mapped date does not abort export generation

- **WHEN** an export row reaches date formatting without a parseable date value
- **THEN** the exporter MUST emit a neutral date placeholder for that row
- **AND** XLSX or CSV generation MUST continue without a Carbon date parsing exception

# Spec Delta

## Purpose

Mutasi Stok lists inventory movements per product for the selected period, locations and businesses. Its Global entry point opens the same report with every assigned business selected.

## ADDED Requirements

### Requirement: Stock mutation report access
The system SHALL provide `Mutasi Stok` at `reports.stock-mutation-report.index`, gated by `stockMutationReports.access`, and `Mutasi Stok Global` at `reports.stock-mutation-report.global`, gated by `stockMutationReports.global.access`.

#### Scenario: Unpermitted user is denied
- **WHEN** a user without the route's permission requests it
- **THEN** the system SHALL deny access

### Requirement: Stock mutation report is business-scoped
The report SHALL follow the shared report business scope. `Mutasi Stok` SHALL default to the session business. `Mutasi Stok Global` SHALL default to every business assigned to the user, and SHALL NOT include businesses the user is not assigned to.

#### Scenario: Global entry point defaults to assigned businesses
- **WHEN** a user assigned to A and B opens `Mutasi Stok Global`
- **THEN** A and B SHALL be selected
- **AND** no movement from an unassigned business SHALL appear

#### Scenario: Movements limited to selected businesses
- **WHEN** A is selected and B is not
- **THEN** only movements in locations of A SHALL be listed

### Requirement: Flat movement list with business column
The report SHALL list one row per movement with date, product code, product name, location, type, quantity in, quantity out and reference. When more than one business is selected, a `Bisnis` column SHALL be shown beside `Lokasi`, and exports SHALL include it.

#### Scenario: Multi-business list shows Bisnis
- **WHEN** A and B are selected
- **THEN** each row SHALL show the business that owns the movement's location
- **AND** CSV and XLSX exports SHALL include the `Bisnis` column

#### Scenario: Reference dates resolve within the movement's business
- **WHEN** a movement references a purchase or sale
- **THEN** its displayed reference date SHALL be resolved from that reference within the movement's own business

## MODIFIED Requirements

### Requirement: Per-customer sales report

The system SHALL provide a "Penjualan Per Customer" report that lists sale detail lines grouped by customer, scoped to the current `setting_id`, reachable via `reports.sale-by-customer.index`, gated by `saleReports.access`, and restricted to non-archived Sales whose exact status is `DISPATCHED`. The status restriction SHALL apply to screen rows, customer totals, sorting, pagination, running totals, XLSX exports, and CSV exports.

#### Scenario: Report renders fully dispatched sales grouped by customer

- **WHEN** a user with `saleReports.access` applies filters and an otherwise matching Sale has exact status `DISPATCHED`
- **THEN** its sale detail lines are listed and grouped by customer for the selected date range
- **AND** its amounts contribute to all report totals and exports

#### Scenario: Sales that are not fully dispatched are excluded

- **WHEN** an otherwise matching Sale has status `DRAFTED`, `WAITING_APPROVAL`, `APPROVED`, `REJECTED`, or `DISPATCHED PARTIALLY`
- **THEN** its detail lines MUST NOT appear in screen results or XLSX/CSV exports
- **AND** its amounts MUST NOT contribute to customer totals, sorting, pagination, running totals, or the grand total

#### Scenario: Returned lifecycle states are excluded

- **WHEN** an otherwise matching Sale has status `RETURNED PARTIALLY` or `RETURNED`
- **THEN** its detail lines MUST NOT appear in screen results or XLSX/CSV exports
- **AND** its amounts MUST NOT contribute to customer totals, sorting, pagination, running totals, or the grand total

#### Scenario: Access is gated by the shared sales reports permission

- **WHEN** a user lacks `saleReports.access`
- **THEN** the route returns 403

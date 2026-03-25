## MODIFIED Requirements

### Requirement: POS Reports MUST Use KPI-First Professional Layout
The POS reports page SHALL present a professional dashboard structure with summary KPIs shown before detailed tables, improving scanability for operational and managerial review.

#### Scenario: KPI summary is shown above detailed sections
- **WHEN** a user opens `/pos/reports` with multi-payment checkout data
- **THEN** the page MUST render a summary KPI area before detailed report sections with correctly aggregated amounts

#### Scenario: KPI values reflect active date range and multi-payment transactions
- **WHEN** the user changes date filters and refreshes, including periods with multi-payment checkouts
- **THEN** KPI values MUST update to reflect the same active date range as detailed report data, with payment amounts correctly summed from individual payment entries

### Requirement: POS Reports MUST Provide Structured Detail Tabs
The reports dashboard SHALL provide tabbed detail sections for daily sales, cashier summary, payment methods, item sales, and supervisor approvals.

#### Scenario: Required tabs are available and functional
- **WHEN** the reports page loads with multi-payment transactions in the database
- **THEN** the interface MUST provide tabs for `Penjualan Harian`, `Ringkasan Kasir`, `Metode Pembayaran`, `Penjualan Produk`, and `Persetujuan Supervisor` with all endpoints working correctly

#### Scenario: Tab switch shows corresponding detail content from multi-payment aggregation
- **WHEN** a user selects a detail tab and the system processes multi-payment checkouts
- **THEN** the page MUST display the data region for that tab with correctly aggregated payment amounts and keep current date filters applied

#### Scenario: Payment aggregation handles multi-payment checkouts correctly
- **WHEN** a checkout has multiple payment entries (e.g., $60 cash + $40 card)
- **THEN** the report MUST correctly aggregate amounts from all payment entries in the breakdown (e.g., cashier report shows correct cash_total and non_cash_total)

### Requirement: Report Data Regions MUST Expose Clear Async States
Each report data region SHALL show explicit loading, empty, and error states during asynchronous fetch cycles.

#### Scenario: Loading state with multi-payment data
- **WHEN** report data is being fetched and involves multi-payment transaction aggregation
- **THEN** the active data region MUST show a visible loading indicator

#### Scenario: Empty state
- **WHEN** a report endpoint returns no data for the selected date range
- **THEN** the active data region MUST show an explicit empty-data message

#### Scenario: Error state no longer occurs due to schema fix
- **WHEN** a report endpoint request previously failed due to column mismatch
- **THEN** the active data region now MUST correctly fetch data using the proper `amount_minor_units` column from the multi-payment schema and show results instead of an error

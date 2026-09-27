## ADDED Requirements

### Requirement: POS Reports MUST Use KPI-First Professional Layout
The POS reports page SHALL present a professional dashboard structure with summary KPIs shown before detailed tables, improving scanability for operational and managerial review.

#### Scenario: KPI summary is shown above detailed sections
- **WHEN** a user opens `/pos/reports`
- **THEN** the page MUST render a summary KPI area before detailed report sections

#### Scenario: KPI values reflect active date range
- **WHEN** the user changes date filters and refreshes
- **THEN** KPI values MUST update to reflect the same active date range as detailed report data

### Requirement: POS Reports MUST Provide Structured Detail Tabs
The reports dashboard SHALL provide tabbed detail sections for daily sales, cashier summary, payment methods, item sales, and supervisor approvals.

#### Scenario: Required tabs are available
- **WHEN** the reports page loads
- **THEN** the interface MUST provide tabs for `Penjualan Harian`, `Ringkasan Kasir`, `Metode Pembayaran`, `Penjualan Produk`, and `Persetujuan Supervisor`

#### Scenario: Tab switch shows corresponding detail content
- **WHEN** a user selects a detail tab
- **THEN** the page MUST display the data region for that tab and keep current date filters applied

### Requirement: Report Data Regions MUST Expose Clear Async States
Each report data region SHALL show explicit loading, empty, and error states during asynchronous fetch cycles.

#### Scenario: Loading state
- **WHEN** report data is being fetched
- **THEN** the active data region MUST show a visible loading indicator

#### Scenario: Empty state
- **WHEN** a report endpoint returns no data for the selected date range
- **THEN** the active data region MUST show an explicit empty-data message

#### Scenario: Error state
- **WHEN** a report endpoint request fails
- **THEN** the active data region MUST show an explicit error message and preserve ability to retry via refresh action

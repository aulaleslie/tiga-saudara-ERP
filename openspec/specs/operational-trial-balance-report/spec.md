# Operational Trial Balance Report Specification

## Purpose

Generate Neraca Saldo (trial balance) reports showing debit and credit movement for operational transaction categories in trial-balance format (opening, period movement, and ending debit/credit columns). The report supports date range filtering and is sourced from the same operational movement events as Buku Besar, scoped to the active tenant setting, and exports to XLSX and CSV.
## Requirements
### Requirement: Reports expose operational Neraca Saldo
The system SHALL provide a `Neraca saldo` report page for users with `reports.access`.

#### Scenario: Authorized user opens Neraca Saldo
- **WHEN** a user with `reports.access` requests the Neraca saldo report route
- **THEN** the system renders the Neraca saldo report page
- **AND** the page title is `Neraca saldo`
- **AND** the report displays the active setting currency.

#### Scenario: Authorized user opens Neraca Saldo from reports landing
- **WHEN** a user with `reports.access` views the Reports landing page
- **THEN** the Neraca saldo card is shown as an actionable report link.

#### Scenario: Unauthorized user cannot open Neraca Saldo
- **WHEN** a user without `reports.access` requests the Neraca saldo report route
- **THEN** the system returns a forbidden response.

### Requirement: Neraca Saldo uses operational movement sources
The system SHALL calculate Neraca saldo from supported operational movement records instead of complete accounting journal or chart-of-account balances.

#### Scenario: Report explains operational source
- **WHEN** Neraca saldo is rendered or exported
- **THEN** the output includes a note explaining that the report is calculated from operational transactions
- **AND** the note states that it does not yet use complete accounting journals or chart-of-account posting.

#### Scenario: Report is scoped to active setting
- **WHEN** supported operational movement records exist for multiple settings
- **THEN** Neraca saldo includes only records for the active `setting_id`.

#### Scenario: Journal-only data is not used as the source of truth
- **WHEN** manual `journal_items` exist without corresponding supported operational movement records
- **THEN** those manual journal items do not create Neraca saldo rows in the operational report.

### Requirement: Neraca Saldo supports date range filtering
The system SHALL calculate Neraca saldo for a selected date range.

#### Scenario: Default period is current date
- **WHEN** the user opens Neraca saldo without applying a filter
- **THEN** the report uses the current date as both the start date and end date.

#### Scenario: User applies date range
- **WHEN** the user selects a start date and end date and applies the filter
- **THEN** the report includes supported operational movement dated within the selected range
- **AND** opening balances are calculated from supported operational movement before the start date.

#### Scenario: Invalid date range is rejected
- **WHEN** the user selects an end date before the start date
- **THEN** the system rejects the filter
- **AND** the report does not export using the invalid range.

#### Scenario: Period preset updates dates
- **WHEN** the user selects a supported period preset such as today, this week, this month, or this year
- **THEN** the start date and end date are updated to the matching period boundaries.

### Requirement: Neraca Saldo presents grouped trial-balance columns
The system SHALL render trial-balance-style rows grouped by operational category with opening, movement, and ending debit/credit columns.

#### Scenario: Report table contains required columns
- **WHEN** Neraca saldo is rendered
- **THEN** the table includes account/category identification columns
- **AND** it includes `Saldo Awal Debit`, `Saldo Awal Credit`, `Pergerakan Debit`, `Pergerakan Credit`, `Saldo Akhir Debit`, and `Saldo Akhir Credit` values.

#### Scenario: Category headers group operational rows
- **WHEN** supported operational rows exist in categories such as assets, liabilities, income, or expenses
- **THEN** the report groups rows under category headers.

#### Scenario: Real COA drill-down is not shown
- **WHEN** Neraca saldo is rendered
- **THEN** the report does not provide chart-of-account drill-down links or claim rows are real COA balances.

#### Scenario: Sales cost is not purchase total
- **WHEN** Neraca saldo renders expense-category rows for a period containing both sales and purchases
- **THEN** Beban Pokok Penjualan or equivalent sales-cost rows are based on sale detail cost snapshots
- **AND** purchase header totals are not shown as sales cost/HPP rows.

### Requirement: Neraca Saldo normalizes eligible operational movement
The system SHALL normalize eligible sales, sale cost snapshots, payments, purchase payable movement, return payments, and expenses into debit and credit movement used by the report.

#### Scenario: Eligible sale creates DPP revenue and receivable movement
- **WHEN** an eligible sale is dated within or before the selected report range
- **THEN** Neraca saldo reflects operational revenue using the sum of `sale_details.sub_total - COALESCE(sale_details.product_tax_amount, 0)` for that sale
- **AND** sale header `tax_amount` and `shipping_amount` do not increase operational revenue
- **AND** the sale creates receivable movement from the authoritative current sale document amount used by the report.

#### Scenario: Header sales discount reduces revenue separately
- **WHEN** an eligible sale has a header or global `discount_amount`
- **THEN** Neraca saldo reflects that discount as a reduction of operational revenue
- **AND** line-level product discounts already reflected in sale detail `sub_total` are not subtracted again.

#### Scenario: Eligible sale creates HPP movement from cost snapshots
- **WHEN** eligible sale details have `cost_unit_snapshot` and current `quantity`
- **THEN** Neraca saldo reflects Beban Pokok Penjualan using the sum of `COALESCE(cost_unit_snapshot, 0) * quantity`
- **AND** `cost_total_snapshot`, purchase header totals, and current product cost are not authoritative HPP sources for this report.

#### Scenario: Missing cost snapshot contributes zero HPP
- **WHEN** an eligible sale detail has a null `cost_unit_snapshot`
- **THEN** that detail contributes zero to Beban Pokok Penjualan movement
- **AND** the report does not recalculate HPP from the product's current average purchase price.

#### Scenario: Active sale payment creates cash and receivable movement
- **WHEN** an active sale payment is dated within or before the selected report range
- **THEN** Neraca saldo reflects the payment as cash/bank inflow and receivable reduction.

#### Scenario: Eligible purchase creates payable movement without HPP
- **WHEN** an eligible purchase is dated within or before the selected report range
- **THEN** Neraca saldo reflects payable movement supported by that purchase where payable rows are shown
- **AND** the purchase header total does not create Beban Pokok Penjualan or operational HPP movement.

#### Scenario: Active purchase payment creates cash and payable movement
- **WHEN** an active purchase payment is dated within or before the selected report range
- **THEN** Neraca saldo reflects the payment as cash/bank outflow and payable reduction.

#### Scenario: Approved expense creates cash and expense movement
- **WHEN** an approved, non-archived expense is dated within or before the selected report range
- **THEN** Neraca saldo reflects the expense as cash/bank outflow and gross operational expense movement.

#### Scenario: Completed sale returns do not reverse DPP revenue or HPP
- **WHEN** a completed sale return exists for a sale whose current sale document is included in the report
- **THEN** Neraca saldo does not create separate revenue reversal or HPP reversal movement from the sale return header or details
- **AND** sale return payment records still create supported cash and receivable movement when refunds are paid.

#### Scenario: Purchase return payments remain cash movement
- **WHEN** completed purchase return payment records are dated within or before the selected report range
- **THEN** Neraca saldo reflects supported purchase return payment movement in cash/bank and payable rows
- **AND** purchase return headers do not create Beban Pokok Penjualan movement.

#### Scenario: Ineligible records are excluded
- **WHEN** draft, rejected, archived, inactive payment, or incomplete lifecycle records exist
- **THEN** their amounts do not contribute to Neraca saldo.

### Requirement: Neraca Saldo calculates opening, movement, ending, and totals
The system SHALL calculate opening debit/credit, period debit/credit, ending debit/credit, and report totals from supported operational movement.

#### Scenario: Opening balance uses prior movement
- **WHEN** a row has eligible movement before the selected start date
- **THEN** Neraca saldo uses that prior movement to calculate the row opening debit or credit balance.

#### Scenario: Period movement uses selected range
- **WHEN** a row has eligible movement between the selected start date and end date
- **THEN** Neraca saldo sums that movement into period debit and period credit columns.

#### Scenario: Ending balance uses opening plus period net movement
- **WHEN** a row has opening balance and period movement
- **THEN** Neraca saldo calculates the ending debit or credit balance from opening net movement plus period net movement.

#### Scenario: Totals sum visible rows
- **WHEN** Neraca saldo is rendered
- **THEN** the total row sums opening debit, opening credit, period debit, period credit, ending debit, and ending credit across visible report rows.

### Requirement: Neraca Saldo exports XLSX
The system SHALL allow authorized users to export Neraca saldo to XLSX using the same filters and calculation output as the screen.

#### Scenario: XLSX export uses current filters
- **WHEN** the user exports Neraca saldo to XLSX after applying a date range
- **THEN** the downloaded file uses the same date range, active setting, rows, category grouping, and totals as the on-screen report.

#### Scenario: XLSX export includes report header
- **WHEN** the XLSX file is generated
- **THEN** it includes the company name, `Neraca saldo` title, period label, currency label, grouped trial-balance headers, and total row.

#### Scenario: XLSX export includes report note
- **WHEN** the XLSX file is generated
- **THEN** it includes the operational-transaction source note.

### Requirement: Neraca Saldo exports CSV
The system SHALL allow authorized users to export Neraca saldo to CSV using the same filters and calculation output as the screen.

#### Scenario: CSV export uses sample-compatible columns
- **WHEN** the user exports Neraca saldo to CSV
- **THEN** the CSV includes columns for category, account identifier, account label, opening debit, opening credit, period debit, period credit, ending debit, and ending credit
- **AND** the data rows match the on-screen report values.

#### Scenario: CSV export emits numeric values
- **WHEN** the CSV file is generated
- **THEN** debit and credit amount columns contain numeric values suitable for spreadsheet import.

### Requirement: Neraca Saldo handles empty movement
The system SHALL show a clear empty state when no supported operational movement or non-zero row balance exists for the selected filters.

#### Scenario: No supported movement exists
- **WHEN** the selected date range and active setting have no supported operational movement before or during the period
- **THEN** Neraca saldo displays an empty state explaining that no operational transactions are available for the selected period.


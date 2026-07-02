# Operational General Ledger Report Specification

## Purpose

Generate Buku Besar (general ledger) reports showing normalized operational movement transactions grouped by operational buckets (cash, receivables, payables, revenue, costs, expenses) with debit/credit columns defined by bucket direction. The report supports date range filtering and bucket selection, scoped to the active tenant setting, and exports to XLSX.
## Requirements
### Requirement: Reports landing exposes Buku Besar
The system SHALL expose Buku Besar as an available report card under Reports > Sekilas bisnis for users with `reports.access`.

#### Scenario: Authorized user opens Buku Besar from reports landing
- **WHEN** a user with `reports.access` views the Reports landing page
- **THEN** the Buku Besar card is shown as an actionable report link

#### Scenario: Unauthorized user cannot access Buku Besar
- **WHEN** a user without `reports.access` requests the Buku Besar report route
- **THEN** the system returns a forbidden response

### Requirement: Buku Besar uses operational transaction buckets
The system SHALL generate Buku Besar from operational transaction data instead of chart-of-account journal balances.

#### Scenario: Report preserves Buku Besar title
- **WHEN** Buku Besar is rendered or exported
- **THEN** the report title is `Buku Besar`

#### Scenario: Report omits account numbers
- **WHEN** Buku Besar is rendered
- **THEN** the report does not display COA account numbers or chart-of-account drill-down links

#### Scenario: Report explains operational source
- **WHEN** Buku Besar is rendered or exported
- **THEN** the output includes a note that the report is calculated from operational transactions and does not yet use accounting journals or COA posting

### Requirement: Buku Besar supports date range filtering
The system SHALL calculate Buku Besar for a selected date range scoped to the active tenant setting.

#### Scenario: Default period is today
- **WHEN** the user opens Buku Besar without selecting a period
- **THEN** the report uses the current date as both the start date and end date

#### Scenario: User filters by date range
- **WHEN** the user selects a start date and end date and applies the filter
- **THEN** the report includes eligible operational movement dated within the selected range

#### Scenario: Report is scoped to current setting
- **WHEN** operational transactions exist for multiple settings
- **THEN** Buku Besar includes only transactions for the active `setting_id`

### Requirement: Buku Besar groups movements by operational bucket
The system SHALL group movement rows by operational bucket names rather than COA accounts.

#### Scenario: Bucket labels are shown as group headers
- **WHEN** the report contains movement for cash, receivable, payable, sale DPP revenue, sale discount, sale cost/HPP, approved expense, purchase payment, or return payment activity
- **THEN** the report groups rows under operational bucket labels such as `Kas & Bank dari Transaksi`, `Piutang Usaha`, `Hutang Usaha`, `Pendapatan Operasional`, `Beban Pokok Penjualan`, `Beban Operasional`, or supported return/payment correction buckets
- **AND** completed purchase headers are not labeled or totaled as Beban Pokok Penjualan.

#### Scenario: Bucket filter limits visible groups
- **WHEN** the user selects one or more operational buckets and applies the filter
- **THEN** Buku Besar shows only the selected buckets and their eligible movement rows

#### Scenario: Non-zero quiet bucket remains visible
- **WHEN** a bucket has no movement in the selected period but has a non-zero beginning or ending balance
- **THEN** Buku Besar still shows the bucket with its balance summary

### Requirement: Buku Besar normalizes eligible operational movement rows
The system SHALL normalize eligible sales, sale cost snapshots, payments, purchase payable movement, return payments, and expenses into dated movement rows with source references and descriptions.

#### Scenario: Eligible sale creates DPP revenue and receivable movement
- **WHEN** an eligible sale is dated within or before the selected report range
- **THEN** Buku Besar reflects operational revenue using the sum of `sale_details.sub_total - COALESCE(sale_details.product_tax_amount, 0)` for that sale
- **AND** sale header `tax_amount` and `shipping_amount` do not increase operational revenue
- **AND** the sale creates receivable movement from the authoritative current sale document amount used by the report.

#### Scenario: Header sales discount reduces revenue separately
- **WHEN** an eligible sale has a header or global `discount_amount`
- **THEN** Buku Besar reflects that discount as a reduction of operational revenue
- **AND** line-level product discounts already reflected in sale detail `sub_total` are not subtracted again.

#### Scenario: Eligible sale creates HPP movement from cost snapshots
- **WHEN** eligible sale details have `cost_unit_snapshot` and current `quantity`
- **THEN** Buku Besar reflects Beban Pokok Penjualan using the sum of `COALESCE(cost_unit_snapshot, 0) * quantity`
- **AND** `cost_total_snapshot`, purchase header totals, and current product cost are not authoritative HPP sources for this report.

#### Scenario: Missing cost snapshot contributes zero HPP
- **WHEN** an eligible sale detail has a null `cost_unit_snapshot`
- **THEN** that detail contributes zero to Beban Pokok Penjualan movement
- **AND** the report does not recalculate HPP from the product's current average purchase price.

#### Scenario: Active sale payment creates cash and receivable movement
- **WHEN** an active sale payment is dated within or before the selected report range
- **THEN** Buku Besar reflects the payment as cash/bank inflow and receivable reduction

#### Scenario: Eligible purchase creates payable movement without HPP
- **WHEN** an eligible purchase is dated within or before the selected report range
- **THEN** Buku Besar reflects payable movement supported by that purchase where payable balances are shown
- **AND** the purchase header total does not create Beban Pokok Penjualan or operational HPP movement.

#### Scenario: Active purchase payment creates cash and payable movement
- **WHEN** an active purchase payment is dated within or before the selected report range
- **THEN** Buku Besar reflects the payment as cash/bank outflow and payable reduction

#### Scenario: Approved expense creates cash and expense movement
- **WHEN** an approved, non-archived expense is dated within or before the selected report range
- **THEN** Buku Besar reflects the expense as cash/bank outflow and gross operational expense movement

#### Scenario: Completed sale returns do not reverse DPP revenue or HPP
- **WHEN** a completed sale return exists for a sale whose current sale document is included in the report
- **THEN** Buku Besar does not create separate revenue reversal or HPP reversal movement from the sale return header or details
- **AND** sale return payment records still create supported cash and receivable movement when refunds are paid.

#### Scenario: Purchase return payments remain cash movement
- **WHEN** completed purchase return payment records are dated within or before the selected report range
- **THEN** Buku Besar reflects supported purchase return payment movement in cash/bank and payable buckets
- **AND** purchase return headers do not create Beban Pokok Penjualan movement.

### Requirement: Buku Besar calculates debit, credit, and running balance
The system SHALL calculate beginning balance, period debit, period credit, running balance, and ending balance for each operational bucket.

#### Scenario: Beginning balance uses prior movement
- **WHEN** a bucket has eligible movement before the selected start date
- **THEN** Buku Besar uses that prior movement to calculate the bucket beginning balance

#### Scenario: Period rows update running balance
- **WHEN** a bucket has multiple eligible movement rows in the selected period
- **THEN** Buku Besar orders the rows by date and source reference and updates the running balance after each row

#### Scenario: Ending balance is shown
- **WHEN** a bucket is rendered
- **THEN** Buku Besar shows the bucket ending balance after applying beginning balance, period debit, and period credit

### Requirement: Buku Besar defines debit and credit by bucket direction
The system SHALL use debit and credit as bucket-direction columns rather than double-entry journal assertions.

#### Scenario: Cash bucket direction
- **WHEN** cash/bank movement is rendered
- **THEN** cash/bank inflow appears as debit and cash/bank outflow appears as credit

#### Scenario: Receivable bucket direction
- **WHEN** receivable movement is rendered
- **THEN** receivable creation appears as debit and receivable reduction appears as credit

#### Scenario: Payable bucket direction
- **WHEN** payable movement is rendered
- **THEN** payable creation appears as credit and payable reduction appears as debit

#### Scenario: Revenue and cost bucket direction
- **WHEN** revenue, sales discount, HPP, or expense movement is rendered
- **THEN** sale DPP revenue creation appears as credit
- **AND** header/global sale discount appears as debit or revenue reduction
- **AND** sale cost/HPP creation and approved expense creation appear as debit
- **AND** purchase headers do not create HPP debit movement.

### Requirement: Buku Besar exports XLSX matching on-screen data
The system SHALL allow authorized users to export the filtered Buku Besar report to XLSX using the same calculation output shown on screen.

#### Scenario: Export uses current filters
- **WHEN** the user exports Buku Besar after selecting a date range and bucket filter
- **THEN** the XLSX file uses the same date range, selected buckets, rows, and balances as the on-screen report

#### Scenario: Export includes report note
- **WHEN** the XLSX file is generated
- **THEN** it includes the operational-transaction source note

### Requirement: Buku Besar handles empty results
The system SHALL show a clear empty state when no operational movement or non-zero bucket balance exists for the selected filters.

#### Scenario: No movement is available
- **WHEN** the selected filters match no eligible movement and no non-zero bucket balances
- **THEN** Buku Besar displays an empty state explaining that no operational transactions are available for the selected period


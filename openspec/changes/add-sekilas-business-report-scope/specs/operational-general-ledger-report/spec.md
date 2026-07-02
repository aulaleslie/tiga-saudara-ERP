## MODIFIED Requirements

### Requirement: Buku Besar supports date range filtering
The system SHALL calculate Buku Besar for a selected date range scoped to the selected business source scope.

#### Scenario: Default period is today
- **WHEN** the user opens Buku Besar without selecting a period
- **THEN** the report uses the current date as both the start date and end date

#### Scenario: Default business scope uses current setting
- **WHEN** the user opens Buku Besar without selecting any business source
- **THEN** the report uses only the current `session('setting_id')`

#### Scenario: User filters by date range
- **WHEN** the user selects a start date and end date and applies the filter
- **THEN** the report includes eligible operational movement dated within the selected range
- **AND** the report applies the effective selected business source scope to the movement calculation

#### Scenario: User selects multiple business sources
- **WHEN** operational transactions exist for multiple settings and the user selects two or more business sources
- **THEN** Buku Besar includes eligible movement whose source document or payment parent belongs to one of the selected settings
- **AND** records from unselected settings do not affect beginning balances, period movement, running balances, or ending balances

#### Scenario: Scope label is visible
- **WHEN** Buku Besar is rendered
- **THEN** the report header identifies the effective business source scope as the selected company name, `Semua Perusahaan`, or `Beberapa Perusahaan`

### Requirement: Buku Besar normalizes eligible operational movement rows
The system SHALL normalize eligible sales, sale cost snapshots, payments, purchase payable movement, return payments, and expenses from the selected business source scope into dated movement rows with source references and descriptions.

#### Scenario: Eligible sale creates DPP revenue and receivable movement
- **WHEN** an eligible sale in the selected business source scope is dated within or before the selected report range
- **THEN** Buku Besar reflects operational revenue using the sum of `sale_details.sub_total - COALESCE(sale_details.product_tax_amount, 0)` for that sale
- **AND** sale header `tax_amount` and `shipping_amount` do not increase operational revenue
- **AND** the sale creates receivable movement from the authoritative current sale document amount used by the report.

#### Scenario: Header sales discount reduces revenue separately
- **WHEN** an eligible sale in the selected business source scope has a header or global `discount_amount`
- **THEN** Buku Besar reflects that discount as a reduction of operational revenue
- **AND** line-level product discounts already reflected in sale detail `sub_total` are not subtracted again.

#### Scenario: Eligible sale creates HPP movement from cost snapshots
- **WHEN** eligible sale details in the selected business source scope have `cost_unit_snapshot` and current `quantity`
- **THEN** Buku Besar reflects Beban Pokok Penjualan using the sum of `COALESCE(cost_unit_snapshot, 0) * quantity`
- **AND** `cost_total_snapshot`, purchase header totals, and current product cost are not authoritative HPP sources for this report.

#### Scenario: Missing cost snapshot contributes zero HPP
- **WHEN** an eligible sale detail in the selected business source scope has a null `cost_unit_snapshot`
- **THEN** that detail contributes zero to Beban Pokok Penjualan movement
- **AND** the report does not recalculate HPP from the product's current average purchase price.

#### Scenario: Active sale payment creates cash and receivable movement
- **WHEN** an active sale payment is dated within or before the selected report range and its sale belongs to the selected business source scope
- **THEN** Buku Besar reflects the payment as cash/bank inflow and receivable reduction

#### Scenario: Eligible purchase creates payable movement without HPP
- **WHEN** an eligible purchase in the selected business source scope is dated within or before the selected report range
- **THEN** Buku Besar reflects payable movement supported by that purchase where payable balances are shown
- **AND** the purchase header total does not create Beban Pokok Penjualan or operational HPP movement.

#### Scenario: Active purchase payment creates cash and payable movement
- **WHEN** an active purchase payment is dated within or before the selected report range and its purchase belongs to the selected business source scope
- **THEN** Buku Besar reflects the payment as cash/bank outflow and payable reduction

#### Scenario: Approved expense creates cash and expense movement
- **WHEN** an approved, non-archived expense in the selected business source scope is dated within or before the selected report range
- **THEN** Buku Besar reflects the expense as cash/bank outflow and gross operational expense movement

#### Scenario: Completed sale returns do not reverse DPP revenue or HPP
- **WHEN** a completed sale return exists in the selected business source scope for a sale whose current sale document is included in the report
- **THEN** Buku Besar does not create separate revenue reversal or HPP reversal movement from the sale return header or details
- **AND** sale return payment records still create supported cash and receivable movement when refunds are paid.

#### Scenario: Purchase return payments remain cash movement
- **WHEN** completed purchase return payment records are dated within or before the selected report range and their purchase returns belong to the selected business source scope
- **THEN** Buku Besar reflects supported purchase return payment movement in cash/bank and payable buckets
- **AND** purchase return headers do not create Beban Pokok Penjualan movement.

### Requirement: Buku Besar calculates debit, credit, and running balance
The system SHALL calculate beginning balance, period debit, period credit, running balance, and ending balance for each operational bucket within the selected business source scope.

#### Scenario: Beginning balance uses prior movement
- **WHEN** a bucket has eligible movement before the selected start date within the selected business source scope
- **THEN** Buku Besar uses that prior movement to calculate the bucket beginning balance

#### Scenario: Period rows update running balance
- **WHEN** a bucket has multiple eligible movement rows in the selected period and selected business source scope
- **THEN** Buku Besar orders the rows by date and source reference and updates the running balance after each row

#### Scenario: Ending balance is shown
- **WHEN** a bucket is rendered
- **THEN** Buku Besar shows the bucket ending balance after applying beginning balance, period debit, and period credit

### Requirement: Buku Besar exports XLSX matching on-screen data
The system SHALL allow authorized users to export the filtered Buku Besar report to XLSX using the same calculation output and business source scope shown on screen.

#### Scenario: Export uses current filters
- **WHEN** the user exports Buku Besar after selecting a date range, bucket filter, and business source scope
- **THEN** the XLSX file uses the same date range, selected buckets, selected business source scope, rows, and balances as the on-screen report

#### Scenario: Export includes report note
- **WHEN** the XLSX file is generated
- **THEN** it includes the operational-transaction source note

#### Scenario: Export labels selected scope
- **WHEN** the XLSX file is generated
- **THEN** the export header identifies the effective business source scope as the selected company name, `Semua Perusahaan`, or `Beberapa Perusahaan`

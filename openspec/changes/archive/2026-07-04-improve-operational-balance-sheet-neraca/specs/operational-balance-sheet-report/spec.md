## MODIFIED Requirements

### Requirement: Neraca report calculates asset rows
The system SHALL present asset rows for cash/bank from transaction payments, customer receivables, as-of inventory value, supported purchase tax input, and other operational asset buckets supported by the available data within the selected business source scope.

#### Scenario: Paid sale increases cash or bank
- **WHEN** an eligible sale in the selected business source scope has a payment dated on or before the as-of date
- **THEN** the payment amount contributes to the cash/bank asset row

#### Scenario: Unpaid sale creates receivable
- **WHEN** an eligible sale in the selected business source scope has an outstanding due amount as of the selected date
- **THEN** the outstanding amount from the authoritative current sale document contributes to the customer receivables asset row
- **AND** completed sale return totals are not subtracted again from receivables when the sale document already reflects post-return values.

#### Scenario: Corrected sale after return is not double-subtracted
- **WHEN** an eligible sale in the selected business source scope has already been corrected for returned quantities and a completed sale return also exists
- **THEN** Neraca calculates customer receivables from the corrected sale document and payments
- **AND** it does not reduce receivables a second time from `sale_returns.total_amount`.

#### Scenario: Inventory value uses stock transactions as of selected date
- **WHEN** stock-managed products have stock transactions in selected business sources on or before the selected as-of date
- **THEN** the calculated `Persediaan Barang` value is based on transaction-replayed stock quantity as of the selected date multiplied by the product average cost source used by the warehouse stock valuation report
- **AND** stock transactions after the selected as-of date do not affect the `Persediaan Barang` row
- **AND** products from unselected settings do not affect the inventory asset row

#### Scenario: Inventory value sums selected business sources
- **WHEN** the user selects multiple business sources
- **THEN** the `Persediaan Barang` row sums as-of stock valuation across those selected settings
- **AND** warehouses, products, transactions, and product prices from unselected settings are excluded.

#### Scenario: Purchase tax input appears when supported
- **WHEN** eligible purchase documents in the selected business source scope include purchase tax amounts dated on or before the selected as-of date
- **THEN** the report presents a supported asset row labeled `PPN Masukan`
- **AND** the amount reflects purchase tax input from eligible operational purchase data within the selected scope.

### Requirement: Neraca report calculates liability rows
The system SHALL present liability rows for supplier payables, customer return obligations, sales tax output, and other operational liability buckets supported by the available data.

#### Scenario: Unpaid purchase creates payable
- **WHEN** an eligible purchase has an outstanding due amount as of the selected date
- **THEN** the outstanding amount contributes to the supplier payables liability row

#### Scenario: Purchase payment reduces cash or bank
- **WHEN** an eligible purchase payment is dated on or before the as-of date
- **THEN** the payment amount reduces the cash/bank asset row

#### Scenario: Approved expense reduces cash or bank
- **WHEN** an approved, non-archived expense is dated on or before the as-of date
- **THEN** the expense amount reduces the cash/bank asset row

#### Scenario: Sale return refund reduces cash without double-reducing receivable
- **WHEN** a sale return refund payment is dated on or before the as-of date
- **THEN** the refund payment reduces cash or bank according to operational payment movement
- **AND** the report does not also subtract the sale return header from receivables when the current sale document is authoritative.

#### Scenario: Sales tax output appears with sample-aligned label
- **WHEN** eligible sales in the selected business source scope include sales tax amounts dated on or before the selected as-of date
- **THEN** the report presents the supported tax liability row as `PPN Keluaran`
- **AND** the amount reflects eligible operational sales tax output within the selected scope.

### Requirement: Neraca report derives equity to balance totals
The system SHALL derive operational equity rows so total liabilities plus equity equals total assets while separately showing prior-year and current-year earnings when supported by operational profit/loss data.

#### Scenario: Prior-year earnings row is calculated
- **WHEN** the selected as-of date is within a calendar year and supported operational profit/loss data exists before that year
- **THEN** the equity section includes `Pendapatan sampai Tahun lalu`
- **AND** the amount equals operational profit/loss from the beginning of supported records through December 31 of the year before the selected as-of date within the selected business source scope.

#### Scenario: Current-period earnings row is calculated
- **WHEN** the selected as-of date is within a calendar year
- **THEN** the equity section includes `Pendapatan Periode ini`
- **AND** the amount equals operational profit/loss from January 1 of the selected as-of year through the selected as-of date within the selected business source scope.

#### Scenario: Residual owner capital balances report totals
- **WHEN** the report has calculated total assets, total liabilities, prior-year earnings, and current-period earnings
- **THEN** the equity section includes a `Modal / Ekuitas` residual row
- **AND** the residual row equals total assets minus total liabilities minus `Pendapatan sampai Tahun lalu` minus `Pendapatan Periode ini`.

#### Scenario: Total liabilities and equity equals total assets
- **WHEN** the report is rendered
- **THEN** total liabilities plus equity equals total assets within currency rounding tolerance

#### Scenario: Operational equity source is explained
- **WHEN** the report is rendered or exported
- **THEN** the output explains that equity and earnings rows are operational derivations and are not complete chart-of-account retained earnings or manual capital ledger balances.

### Requirement: Neraca report exports XLSX matching on-screen data
The system SHALL allow authorized users to export the filtered Neraca report to XLSX using the same calculation output, business source scope, row labels, earnings rows, tax rows, and as-of inventory valuation shown on screen.

#### Scenario: Export uses current filters
- **WHEN** the user exports the Neraca report after selecting an as-of date and business source scope
- **THEN** the XLSX file uses the same as-of date, selected business source scope, and report rows as the on-screen report

#### Scenario: Export includes report note
- **WHEN** the XLSX file is generated
- **THEN** it includes the operational-transaction source note
- **AND** the note describes that inventory uses transaction-replayed stock and average cost rather than full accounting journal valuation.

#### Scenario: Export labels selected scope
- **WHEN** the XLSX file is generated
- **THEN** the export header identifies the effective business source scope as the selected company name, `Semua Perusahaan`, or `Beberapa Perusahaan`

#### Scenario: Export includes earnings and tax rows
- **WHEN** the XLSX file is generated
- **THEN** it includes the same supported `PPN Masukan`, `PPN Keluaran`, `Modal / Ekuitas`, `Pendapatan sampai Tahun lalu`, and `Pendapatan Periode ini` rows shown on screen.

## ADDED Requirements

### Requirement: Neraca report exports CSV
The system SHALL allow authorized users to export the filtered Neraca report to CSV using the same calculation output, selected business source scope, row labels, tax rows, earnings rows, and as-of inventory valuation shown on screen.

#### Scenario: CSV export action is available
- **WHEN** an authorized user opens the Neraca report
- **THEN** the report provides a CSV export action alongside the existing XLSX export action.

#### Scenario: CSV export uses current filters
- **WHEN** the user exports CSV after selecting an as-of date and business source scope
- **THEN** the CSV file uses the same as-of date, selected business source scope, and report rows as the on-screen report.

#### Scenario: CSV contains spreadsheet-friendly rows
- **WHEN** the CSV file is generated
- **THEN** amount columns contain numeric values suitable for spreadsheet import
- **AND** the CSV includes enough section or row-label context to identify asset, liability, and equity rows.

#### Scenario: CSV includes new Neraca rows
- **WHEN** the CSV file is generated for a report containing supported values
- **THEN** it includes the same supported `Persediaan Barang`, `PPN Masukan`, `PPN Keluaran`, `Modal / Ekuitas`, `Pendapatan sampai Tahun lalu`, and `Pendapatan Periode ini` rows shown on screen.

#### Scenario: CSV export is read-only
- **WHEN** a user exports the Neraca report as CSV
- **THEN** the system does not create products, update product stock, update product prices, create stock transactions, or mutate operational documents.

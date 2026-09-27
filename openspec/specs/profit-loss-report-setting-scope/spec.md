# profit-loss-report-setting-scope Specification

## Purpose
TBD - created by archiving change add-profit-loss-report-setting-scope. Update Purpose after archive.
## Requirements
### Requirement: Profit/loss report supports selectable company scope
The system SHALL allow a user with `reports.access` to choose which companies/settings are included in the existing Laporan Laba Rugi report, and the selected scope SHALL apply to DPP sales, global sales discount, sales cost, approved expenses, and final profit/loss totals.

#### Scenario: Default scope uses current setting
- **WHEN** a permitted user opens `/profit-loss-report` without selecting any company scope
- **THEN** the report SHALL calculate totals using only the current `session('setting_id')`
- **AND** the report SHALL calculate `Penjualan` from current scoped sale detail DPP values
- **AND** the report SHALL calculate `Diskon Penjualan` from current scoped sale header global discounts
- **AND** the report SHALL calculate sales cost from scoped sale detail average cost snapshots multiplied by current sale detail quantities
- **AND** the report SHALL calculate Laba (Rugi) as total pendapatan minus sales cost minus approved gross expenses

#### Scenario: User selects multiple settings
- **WHEN** a permitted user selects two or more settings in the Laporan Laba Rugi company scope filter and applies the report
- **THEN** the report SHALL calculate DPP sales, global sales discount, sales cost, gross expenses, and profit/loss using only records whose `setting_id` is one of the selected settings
- **AND** records from unselected settings SHALL NOT affect the totals

#### Scenario: User selects all settings
- **WHEN** a permitted user selects every available setting in the company scope filter and applies the report
- **THEN** the report SHALL calculate totals across all selected settings
- **AND** the report scope label SHALL indicate `Semua Perusahaan`

#### Scenario: Report presents sample-aligned operational sections
- **WHEN** the report renders operational rows for a selected date range
- **THEN** the report SHALL include operational sections and subtotals for `Pendapatan`, `Beban Pokok Pendapatan`, `Laba Kotor`, `Beban Operasional`, `Laba Operasional`, `Pendapatan (Beban Lain-lain)`, and `Laba (Rugi)`
- **AND** the report SHALL NOT include chart-of-account codes, account drill-down links, or accounting ledger rows
- **AND** completed purchases and purchase returns SHALL NOT be used as direct profit/loss cost rows

#### Scenario: Sales revenue uses DPP only
- **WHEN** scoped finalized sale details include `sub_total` and `product_tax_amount`
- **THEN** the `Penjualan` row SHALL equal the sum of `sale_details.sub_total - sale_details.product_tax_amount`
- **AND** sale header `tax_amount` SHALL NOT be added to the `Penjualan` row
- **AND** sale header `shipping_amount` SHALL NOT be added to the `Penjualan` row

#### Scenario: Global sales discount has its own row
- **WHEN** scoped finalized sales include header/global `discount_amount`
- **THEN** the report SHALL show a `Diskon Penjualan` row
- **AND** the `Diskon Penjualan` row SHALL equal the negative sum of scoped sale header/global discounts
- **AND** line-level product discounts SHALL NOT be subtracted again from this row

#### Scenario: Sale returns are not report sources
- **WHEN** scoped completed sale return records exist in the selected date range
- **THEN** the report SHALL NOT show a `Retur Penjualan` row
- **AND** the report SHALL NOT subtract `sale_returns` or `sale_return_details` from revenue, HPP, or profit/loss
- **AND** the report SHALL rely on the current sale document state as the authoritative post-return sales basis

#### Scenario: HPP uses average cost snapshot and current quantity
- **WHEN** scoped finalized sale details have `cost_unit_snapshot` and current `quantity`
- **THEN** the `Beban Pokok Pendapatan` amount SHALL equal the sum of `sale_details.cost_unit_snapshot * sale_details.quantity`
- **AND** `sale_details.cost_total_snapshot` SHALL NOT be used as the authoritative HPP amount for this report

#### Scenario: Missing HPP unit snapshot is stable
- **WHEN** a scoped finalized sale detail has a null `cost_unit_snapshot`
- **THEN** that detail SHALL contribute zero to `Beban Pokok Pendapatan`
- **AND** the report SHALL NOT recalculate HPP from the current product average purchase price

#### Scenario: Expenses remain gross
- **WHEN** approved non-archived expenses are included in the selected setting scope and date range
- **THEN** `Beban Operasional` SHALL include the stored expense amount including tax
- **AND** the report SHALL NOT exclude expense tax or calculate expense DPP

### Requirement: Profit/loss report export preserves selected company scope
The system SHALL export Laporan Laba Rugi using the same selected setting scope, row structure, and operational DPP/HPP calculation as the screen report.

#### Scenario: Export uses selected settings
- **WHEN** a permitted user selects multiple settings and exports the Laporan Laba Rugi report
- **THEN** the Excel export SHALL calculate totals from the same selected setting IDs as the screen report
- **AND** the exported DPP sales, global sales discount, sales cost, gross expenses, and profit/loss subtotal values SHALL match the screen report for the same date range and setting scope

#### Scenario: Export labels selected scope
- **WHEN** a permitted user exports a Laporan Laba Rugi report for exactly one effective setting
- **THEN** the export header SHALL identify that setting's company name

#### Scenario: Export labels all-company scope
- **WHEN** a permitted user exports a Laporan Laba Rugi report after selecting every available setting
- **THEN** the export header SHALL identify the scope as `Semua Perusahaan`

#### Scenario: Export uses sample-aligned operational rows
- **WHEN** a permitted user exports the Laporan Laba Rugi report
- **THEN** the Excel file SHALL present the same operational sections and subtotal labels as the screen report
- **AND** it SHALL present `Penjualan`, `Diskon Penjualan`, `Beban Pokok Pendapatan`, `Beban Operasional`, and `Laba (Rugi)` using the same formulas as the screen report
- **AND** it SHALL NOT present completed purchases, purchase returns, sale returns, chart-of-account codes, or account drill-down links as profit/loss rows

### Requirement: Multi-company profit/loss access uses reports access
The system SHALL allow any user with `reports.access` to use the Laporan Laba Rugi company scope selector without requiring an additional global-report permission.

#### Scenario: Reports access permits multi-company scope
- **WHEN** a user with `reports.access` visits `/profit-loss-report`
- **THEN** the user SHALL be able to select one or more settings for the report scope
- **AND** the system SHALL NOT require a separate global report permission for this selector

#### Scenario: Existing access denial remains unchanged
- **WHEN** a user without `reports.access` visits `/profit-loss-report`
- **THEN** the system SHALL deny access to the report page


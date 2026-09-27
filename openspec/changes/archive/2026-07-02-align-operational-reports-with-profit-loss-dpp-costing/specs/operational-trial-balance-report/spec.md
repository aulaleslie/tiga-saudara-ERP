## MODIFIED Requirements

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

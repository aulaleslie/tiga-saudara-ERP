## MODIFIED Requirements

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

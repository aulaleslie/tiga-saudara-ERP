## MODIFIED Requirements

### Requirement: Payment reconciliation uses adjusted document total
The purchase and sales importers SHALL reconcile source total, resolved payment amount, and preferred outstanding balance against the adjusted imported document total. The sales importer SHALL additionally allow narrowly bounded historical source-total precision drift when settlement fields reconcile to the source `Total` and the drift satisfies explicit absolute and relative limits.

#### Scenario: Fully paid adjusted purchase imports with payment row
- **WHEN** a purchase CSV invoice group has `Pembayaran` equal to the adjusted document total
- **AND** preferred outstanding balance equals zero
- **THEN** the purchase importer MUST create the purchase with `total_amount`, `paid_amount`, and `due_amount` reconciled to the adjusted document total
- **AND** the importer MUST create exactly one active purchase payment row for the resolved payment amount

#### Scenario: Fully paid adjusted sale imports with payment row
- **WHEN** a sales CSV invoice group has `Pembayaran` equal to the adjusted document total
- **AND** preferred outstanding balance equals zero
- **THEN** the sales importer MUST create the sale with `total_amount`, `paid_amount`, and `due_amount` reconciled to the adjusted document total
- **AND** the importer MUST create exactly one active sale payment row for the resolved payment amount

#### Scenario: Historical sales precision drift reconciles to source total
- **WHEN** a sales CSV invoice group has complete row data, consistent repeated payment fields, `Pembayaran` plus any `Jumlah Pemotongan` plus preferred outstanding balance equal to source `Total`, and the adjusted document total differs from source `Total` only by a drift within the configured absolute and relative precision-drift limits
- **THEN** the sales importer MUST accept the invoice as reconciled
- **AND** the created sale MUST use source `Total` for `total_amount`
- **AND** the created sale MUST keep `paid_amount`, `due_amount`, and active payment rows reconciled to source `Total`

#### Scenario: Historical sales precision drift is observable
- **WHEN** the sales importer accepts an invoice through the precision-drift path
- **THEN** the importer MUST record the invoice number, batch identifier, source total, recomputed adjusted document total, drift amount, and affected row identifiers in a structured log entry or equivalent import processing context

#### Scenario: Purchase source total drift still fails
- **WHEN** a purchase CSV invoice group has source `Total` that does not reconcile with the adjusted document total within the existing purchase source-total tolerance
- **THEN** the importer MUST mark the whole invoice group invalid
- **AND** the row error message MUST identify a document total or payment total mismatch

#### Scenario: Sales source total mismatch outside drift limits still fails
- **WHEN** a sales CSV invoice group has source `Total` that differs from the adjusted document total by more than the configured precision-drift limits
- **THEN** the importer MUST mark the whole invoice group invalid
- **AND** the row error message MUST identify a document total or payment total mismatch

#### Scenario: Sales drift with payment mismatch still fails
- **WHEN** a sales CSV invoice group has adjusted document total drift within the configured precision-drift limits
- **AND** `Pembayaran` plus any `Jumlah Pemotongan` plus preferred outstanding balance does not reconcile to source `Total`
- **THEN** the importer MUST mark the whole invoice group invalid
- **AND** the importer MUST NOT create the sale document or payment rows for that invoice group

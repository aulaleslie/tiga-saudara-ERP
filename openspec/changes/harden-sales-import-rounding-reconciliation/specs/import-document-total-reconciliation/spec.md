## MODIFIED Requirements

### Requirement: Payment reconciliation uses adjusted document total
The purchase and sales importers SHALL reconcile source total, resolved payment amount, and preferred outstanding balance against the adjusted imported document total. For sales imports where CSV `Total` is authoritative through current-status mapping, the importer SHALL use one canonical adjusted generated sale total for source-total reconciliation, sale header persistence, final settlement validation, and payment row creation.

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

#### Scenario: Sales canonical total is reused for final settlement validation
- **WHEN** a sales CSV invoice group has high-precision `harga_satuan`, `pajak`, or `Diskon` values whose raw recomputation rounds differently from the source-reconciled generated total
- **AND** the source `Total` is authoritative through current-status mapping
- **THEN** the sales importer MUST persist the source-reconciled canonical generated sale total
- **AND** the final group settlement validation MUST compare paid, deduction, and due amounts against that same canonical generated sale total
- **AND** the importer MUST NOT invalidate the group only because a later raw recomputation has a different cent value within the accepted precision adjustment

#### Scenario: Source total mismatch still fails
- **WHEN** source `Total` does not reconcile with the calculated line total after applying document `Diskon` and `Biaya Pengiriman`
- **THEN** the importer MUST mark the whole invoice group invalid
- **AND** the row error message MUST identify a document total or payment total mismatch

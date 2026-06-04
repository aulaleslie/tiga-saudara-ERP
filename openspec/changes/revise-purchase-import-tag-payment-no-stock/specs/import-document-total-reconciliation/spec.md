## MODIFIED Requirements

### Requirement: Payment reconciliation uses adjusted document total
The purchase and sales importers SHALL reconcile source total, resolved payment amount, and preferred outstanding balance against the adjusted imported document total. For purchase imports, CSV `Total` SHALL be the authoritative settlement total when status-based payment resolution is needed or source payment fields do not add up cleanly.

#### Scenario: Fully paid adjusted purchase imports with payment row
- **WHEN** a purchase CSV invoice group has `Status Hari Ini` equal to `Lunas`
- **AND** source CSV `Total` is present
- **THEN** the purchase importer MUST create the purchase with `paid_amount` equal to source CSV `Total`
- **AND** the purchase importer MUST create the active payment row or rows needed for active payment rows to sum to the resolved paid amount
- **AND** the purchase importer MUST set `due_amount` to `0.00`

#### Scenario: Fully paid adjusted sale imports with payment row
- **WHEN** a sales CSV invoice group has `Pembayaran` equal to the adjusted document total
- **AND** preferred outstanding balance equals zero
- **THEN** the sales importer MUST create the sale with `total_amount`, `paid_amount`, and `due_amount` reconciled to the adjusted document total
- **AND** the importer MUST create exactly one active sale payment row for the resolved payment amount

#### Scenario: Purchase source total drives settlement when fields mismatch
- **WHEN** a purchase CSV invoice group has a source CSV `Total`
- **AND** the payment fields do not reconcile with the calculated imported document total
- **AND** `Status Hari Ini` is one of `Lunas`, `Belum Dibayar`, `Terbayar Sebagian`, or `Lewat Jatuh Tempo`
- **THEN** the purchase importer MUST use source CSV `Total` to derive paid and due amounts according to the purchase status mapping
- **AND** the importer MUST NOT mark the group invalid solely because the non-authoritative payment fields fail to add up to the calculated imported document total

#### Scenario: Sales source total mismatch still fails
- **WHEN** source `Total` does not reconcile with the calculated sales line total after applying document `Diskon` and `Biaya Pengiriman`
- **THEN** the sales importer MUST mark the whole invoice group invalid
- **AND** the row error message MUST identify a document total or payment total mismatch

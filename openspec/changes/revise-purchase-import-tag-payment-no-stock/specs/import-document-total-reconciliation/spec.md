## MODIFIED Requirements

### Requirement: Payment reconciliation uses adjusted document total
The sales importer SHALL reconcile resolved payment amount and preferred outstanding balance against the calculated imported document total (sum of adjusted line totals with tax, discount, and shipping applied). The purchase importer SHALL treat CSV `Status Hari Ini` and CSV `Total` as the authoritative current document state; calculated purchase line totals SHALL complement that state by creating item details, and any document-total drift SHALL be reconciled into document-level discount or shipping adjustment before settlement allocation so that `paid_amount + due_amount == total_amount` always holds.

#### Scenario: Fully paid adjusted purchase imports with payment row
- **WHEN** a purchase CSV invoice group has `Status Hari Ini` equal to `Lunas`
- **THEN** the purchase importer MUST create the purchase with `paid_amount` equal to the CSV `Total`
- **AND** the purchase importer MUST create the active payment row or rows needed for active payment rows to sum to the resolved paid amount
- **AND** the purchase importer MUST set `due_amount` to `0.00`
- **AND** the purchase importer MUST reconcile the persisted purchase `total_amount` to the CSV `Total`

#### Scenario: Fully paid adjusted sale imports with payment row
- **WHEN** a sales CSV invoice group has `Pembayaran` equal to the adjusted document total
- **AND** preferred outstanding balance equals zero
- **THEN** the sales importer MUST create the sale with `total_amount`, `paid_amount`, and `due_amount` reconciled to the adjusted document total
- **AND** the importer MUST create exactly one active sale payment row for the resolved payment amount

#### Scenario: Purchase status drives settlement when fields mismatch
- **WHEN** a purchase CSV invoice group has a source CSV `Total`
- **AND** the payment fields do not reconcile with the calculated imported document total
- **AND** `Status Hari Ini` is one of `Lunas`, `Belum Dibayar`, `Terbayar Sebagian`, or `Lewat Jatuh Tempo`
- **THEN** the purchase importer MUST derive paid and due amounts from `Status Hari Ini` and `Pembayaran` against the CSV `Total` according to the purchase status mapping
- **AND** the importer MUST adjust purchase document-level discount or shipping allocation so generated owner document totals sum to the CSV `Total`
- **AND** settlement MUST be allocated only after owner document totals reconcile to the CSV `Total` so no owner group receives a negative due amount
- **AND** the importer MUST NOT mark the group invalid solely because the non-authoritative payment fields fail to add up to the calculated imported document total

#### Scenario: Sales source total mismatch still fails
- **WHEN** source `Total` does not reconcile with the calculated sales line total after applying document `Diskon` and `Biaya Pengiriman`
- **THEN** the sales importer MUST mark the whole invoice group invalid
- **AND** the row error message MUST identify a document total or payment total mismatch

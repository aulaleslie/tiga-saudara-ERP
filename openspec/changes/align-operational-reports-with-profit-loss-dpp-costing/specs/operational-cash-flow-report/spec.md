## MODIFIED Requirements

### Requirement: Arus Kas uses operational cash movement sources
The system SHALL calculate Arus Kas from supported operational cash movement records instead of complete accounting journal, chart-of-account, bank ledger, opening capital balances, sales DPP revenue, or non-cash HPP values.

#### Scenario: Report explains operational source
- **WHEN** Arus Kas is rendered or exported
- **THEN** the output includes a note explaining that the report is calculated from supported operational cash movements
- **AND** the note states that it does not yet use complete accounting journals, chart-of-account posting, bank ledger balances, opening capital, bank revaluation data, non-cash sales DPP revenue, or non-cash HPP values.

#### Scenario: Report is scoped to active setting
- **WHEN** operational cash movement records exist for multiple settings
- **THEN** Arus Kas includes only records for the active `setting_id`.

### Requirement: Arus Kas classifies operating cash movement
The system SHALL classify supported payment and expense records into operating cash-flow rows without adding non-cash sales DPP revenue or HPP movement.

#### Scenario: Sale payments increase customer receipts
- **WHEN** active sale payments exist within the selected period for eligible sales in the active setting
- **THEN** their amounts increase `Penerimaan dari pelanggan`.

#### Scenario: Purchase payments increase supplier payments
- **WHEN** active purchase payments exist within the selected period for eligible purchases in the active setting
- **THEN** their amounts decrease `Pembayaran ke pemasok`.

#### Scenario: Sale return refunds reduce operating cash
- **WHEN** sale return payment records exist within the selected period for completed sale returns in the active setting
- **THEN** their amounts reduce operating cash in an appropriate operating row.

#### Scenario: Purchase return refunds increase operating cash
- **WHEN** purchase return payment records exist within the selected period for completed purchase returns in the active setting
- **THEN** their amounts increase operating cash in an appropriate operating row.

#### Scenario: Approved expenses reduce operating cash
- **WHEN** approved, non-archived expenses exist within the selected period in the active setting
- **THEN** their amounts decrease `Pengeluaran operasional`.

#### Scenario: Sales DPP and HPP do not create cash-flow rows
- **WHEN** eligible sale details have DPP revenue or sale cost snapshot HPP in the selected period
- **THEN** those non-cash values do not create Arus Kas rows unless an associated supported payment or refund cash movement exists.

#### Scenario: Ineligible records are excluded
- **WHEN** draft, rejected, archived, inactive payment, or incomplete lifecycle records exist
- **THEN** their amounts do not contribute to Arus Kas.

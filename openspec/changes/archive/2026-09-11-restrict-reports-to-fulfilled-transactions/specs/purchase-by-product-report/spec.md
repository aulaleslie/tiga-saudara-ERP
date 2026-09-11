## MODIFIED Requirements

### Requirement: Purchase invoice details are aggregated by product
The report SHALL aggregate current persisted purchase-detail quantities and values by product only from report-eligible purchases, scoped to the active setting and effective purchase reporting date. These persisted values SHALL already represent any approved settlement modification.

#### Scenario: Eligible purchase contributes current detail values
- **WHEN** a `RECEIVED` or `RETURNED PARTIALLY` purchase is inside the selected scope and period
- **THEN** its current persisted detail quantities and values are included

#### Scenario: Partial receipt is excluded
- **WHEN** a purchase is only `RECEIVED PARTIALLY`
- **THEN** none of its details contribute to the product aggregate

### Requirement: Lifecycle-valid purchase returns are aggregated by product
The report SHALL NOT independently aggregate or subtract purchase-return details because approved settlement modifications are represented by the persisted target purchase details.

#### Scenario: Settled return is not deducted twice
- **WHEN** a settlement has reduced a target purchase detail
- **THEN** the report uses that reduced detail value
- **AND** does not subtract the associated purchase-return detail

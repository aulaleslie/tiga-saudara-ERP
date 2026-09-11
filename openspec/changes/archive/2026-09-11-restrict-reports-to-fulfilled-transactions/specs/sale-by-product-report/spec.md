## MODIFIED Requirements

### Requirement: Invoice sales are aggregated by product
The report SHALL aggregate current persisted sale-detail quantities and values by product only from report-eligible sales, scoped to the selected settings and effective sale reporting date. These persisted values SHALL already represent any approved settlement modification.

#### Scenario: Eligible sale contributes current detail values
- **WHEN** a `DISPATCHED` or `RETURNED PARTIALLY` sale is inside the selected scope and period
- **THEN** its current persisted detail quantities and values are included

#### Scenario: Partial dispatch is excluded
- **WHEN** a sale is only `DISPATCHED PARTIALLY`
- **THEN** none of its details contribute to the product aggregate

### Requirement: Received sales returns are aggregated by product
The report SHALL NOT independently aggregate or subtract sales-return details because approved settlement modifications are represented by the persisted target sale details.

#### Scenario: Settled return is not deducted twice
- **WHEN** a settlement has reduced a target sale detail
- **THEN** the report uses that reduced detail value
- **AND** does not subtract the associated sale-return detail

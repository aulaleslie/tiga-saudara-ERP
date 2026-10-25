## MODIFIED Requirements

### Requirement: Return rounding applies prospectively without historical repair
The new policy SHALL apply to future cash-return executions, including executions against older sales. Deployment, reads and previews SHALL NOT rewrite historical completed returns or balances. The dedicated explicit POS return balance repair SHALL be permitted to correct proven sale header/balance drift, including for transaction 9218, while preserving completed returns and authoritative rows. Transaction 9218 SHALL NOT be used for mutating verification.

#### Scenario: Deployment preserves transaction 9218
- **WHEN** the change is deployed or transaction 9218 is read
- **THEN** its existing sale balances, completed returns, payments and checkout snapshots SHALL remain unchanged

#### Scenario: Older sale has historical returns
- **WHEN** an older sale receives a new cash return after deployment
- **THEN** historical committed quantity and amounts SHALL count against remaining eligibility and valuation
- **AND** historical evidence SHALL remain unchanged while the new allocation is bounded by the stored residual

#### Scenario: Explicit corrective repair
- **WHEN** the dedicated balance repair explicitly selects a completed transaction and proves historical header drift
- **THEN** it SHALL correct only the proven header/balance drift with audit evidence
- **AND** completed returns, payments and original checkout snapshots SHALL remain unchanged

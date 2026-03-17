## ADDED Requirements

### Requirement: Non-cash tender SHALL prioritize terminal-owner split groups
For mixed-owner split posting, the system SHALL allocate non-cash payment amounts to split groups owned by the terminal checkout setting before allocating to non-terminal owner groups.

#### Scenario: Non-cash amount is less than terminal-owner share
- **WHEN** checkout has terminal-owner group total of 40000 and non-cash tender of 30000
- **THEN** all 30000 non-cash is allocated to terminal-owner groups
- **AND** non-terminal groups receive zero non-cash allocation.

#### Scenario: Non-cash amount exceeds terminal-owner share
- **WHEN** checkout has terminal-owner group total of 40000 and non-cash tender of 70000
- **THEN** first 40000 non-cash is allocated to terminal-owner groups
- **AND** remaining 30000 non-cash is allocated to remaining group balances using deterministic proportional allocation.

### Requirement: Residual balances SHALL be allocated with deterministic proportional rounding
After applying non-cash priority, all remaining balances SHALL be allocated proportionally in minor units with deterministic tie-break behavior.

#### Scenario: Residual allocation with fractional cents
- **WHEN** residual amount distribution generates fractional minor-unit shares across groups
- **THEN** allocation uses largest-remainder minor-unit strategy
- **AND** tie-break order is deterministic so repeated runs produce identical results.

### Requirement: Payment-by-group allocation matrix SHALL reconcile exactly
The system MUST reconcile payment allocations across both dimensions: by payment row and by split group.

#### Scenario: Matrix reconciliation checks
- **WHEN** finalize computes payment allocations for N payment rows across M split groups
- **THEN** sum of allocations per payment row equals that row amount
- **AND** sum of allocations per split group equals that group grand total
- **AND** total matrix sum equals checkout grand total.

### Requirement: Allocation outputs SHALL be persisted for replay and downstream reporting
The system SHALL persist per-row and per-group allocation outputs so finalize replay, receipts, reconciliation, and reporting can reconstruct the exact tender composition.

#### Scenario: Replay returns same allocation outputs
- **WHEN** finalize is retried with the same idempotency key
- **THEN** the response reuses previously persisted payment-by-group allocations
- **AND** no recalculation drift appears in returned totals.

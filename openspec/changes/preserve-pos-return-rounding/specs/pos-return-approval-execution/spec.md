## ADDED Requirements

### Requirement: Cash returns use cumulative rounded commercial valuation
Future POS cash-return execution SHALL value each commercial line from its persisted returnable charged amount and original quantity. It SHALL round cumulative returned value with the frozen increment using half-up rounding, cap it at the original returnable amount, and subtract previously committed return value. It SHALL NOT independently round internal owner or bundle fragments.

#### Scenario: First partial marker return
- **WHEN** 1 of 120 markers charged at Rp790000 is cash-returned with increment Rp100
- **THEN** the return amount SHALL be Rp6600 and the remaining commercial value SHALL be Rp783400

#### Scenario: Multiple requests equal one cumulative request
- **WHEN** two markers from that source line are returned through two successive requests
- **THEN** cumulative valuation SHALL be Rp13200 and the second request SHALL be Rp6600
- **AND** the cumulative amount SHALL equal a single request returning two markers

#### Scenario: Final return consumes residual
- **WHEN** all remaining eligible quantity of a commercial line is cash-returned
- **THEN** the return SHALL consume its exact remaining returnable amount, even if outside the increment
- **AND** total committed returns SHALL equal the original returnable amount without a negative residual

#### Scenario: Zero increment retains currency precision
- **WHEN** the frozen increment is zero
- **THEN** cumulative valuation SHALL use two-decimal currency precision without increment rounding

### Requirement: First committed cash return freezes rounding configuration
The system SHALL freeze the POS transaction business's rounding increment at the first successful cash-return execution for each source sale under this policy. Subsequent partial cash returns for that sale SHALL reuse it. Drafts and previews SHALL NOT reserve the setting; approval SHALL reject stale preview amounts when the effective configuration or cumulative return state changes.

#### Scenario: Configuration changes after a completed partial return
- **WHEN** a source sale first cash-returns using Rp100 and the business later changes its increment to Rp500
- **THEN** later partial cash returns for that sale SHALL continue using Rp100

#### Scenario: Configuration changes before first execution
- **WHEN** a first-return preview used Rp100 but the setting becomes Rp500 before execution
- **THEN** execution SHALL require a fresh preview and SHALL NOT commit stale monetary amounts

#### Scenario: Failed approval does not freeze configuration
- **WHEN** first-return execution fails or rolls back
- **THEN** no rounding context or cumulative allocation SHALL be committed

### Requirement: Source sale correction preserves remaining monetary authority
Cash returns SHALL subtract the committed return amount from the stored commercial value. Unaffected detail totals SHALL remain exact. Sale headers SHALL aggregate authoritative remaining values without reconstructing them from unit prices or rounding the remaining balance again. Tax, discount, owner and bundle allocations SHALL reconcile to those values without duplicate deductions.

#### Scenario: Return of another item leaves markers unchanged
- **WHEN** another product is returned from a sale containing 120 markers with persisted subtotal Rp790000 and unit price Rp6583.33
- **THEN** the marker subtotal SHALL remain Rp790000
- **AND** header recalculation SHALL NOT substitute Rp789999.60

#### Scenario: Rounded return is subtracted once
- **WHEN** a line worth Rp790000 receives a committed Rp6600 return
- **THEN** its remaining value SHALL be Rp783400 without a second increment-rounding pass

### Requirement: Monetary return evidence agrees across approval and settlement
The system SHALL use the same committed rounded commercial amount for preview, approval, linked return totals, source-sale reduction, and aggregate debt reduction plus cash refund. Retry and concurrent requests SHALL NOT allocate that value twice. Replacement-only returns SHALL retain their existing commercial behavior.

#### Scenario: Paid and unpaid portions share one correction amount
- **WHEN** a committed rounded return has both debt-reduction and refundable portions
- **THEN** their sum SHALL equal the committed return amount and all linked monetary evidence SHALL reconcile to it

#### Scenario: Concurrent or repeated approval
- **WHEN** concurrent requests target the same remaining quantity or a completed approval is retried
- **THEN** execution SHALL serialize and revalidate eligibility and cumulative amounts
- **AND** no duplicate refund or sale reduction SHALL occur

### Requirement: Return rounding applies prospectively without historical repair
The new policy SHALL apply to future cash-return executions, including executions against older sales. Deployment, reads and previews SHALL NOT rewrite historical completed returns or balances. Transaction 9218 SHALL NOT be selected for repair or used for mutating verification.

#### Scenario: Deployment preserves transaction 9218
- **WHEN** the change is deployed or transaction 9218 is read
- **THEN** its existing sale balances, completed returns, payments and checkout snapshots SHALL remain unchanged

#### Scenario: Older sale has historical returns
- **WHEN** an older sale receives a new cash return after deployment
- **THEN** historical committed quantity and amounts SHALL count against remaining eligibility and valuation
- **AND** historical evidence SHALL remain unchanged while the new allocation is bounded by the stored residual

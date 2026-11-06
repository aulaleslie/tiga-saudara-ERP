# Spec Delta

## MODIFIED Requirements

### Requirement: Fully completed standard Sales Return archives its source Sale
When cumulative physically received standard cash-return quantities fully cover each source Sale dispatched line and every contributing settlement is final, the system SHALL mark the source Sale returned and archive it with actor, time, and Sales Return reference evidence. Repair/replacement and cannot-process quantities MUST NOT prove cash-return coverage. Archival MUST NOT require POS-only mutation of Sale detail or dispatch quantities.

#### Scenario: Full standard cash refund completes
- **WHEN** a standard Sales Return has received the full dispatched quantity and every required cash-return settlement becomes final
- **THEN** the source Sale SHALL have `RETURNED` status
- **AND** it SHALL persist `archived_at`, `archived_by`, and an audit note containing the Sales Return reference

#### Scenario: Partial standard return completes settlement
- **WHEN** cumulative received and completed standard cash returns cover less than the source Sale's dispatched quantity
- **THEN** the source Sale SHALL remain unarchived and returned-partially

#### Scenario: Multiple standard returns cumulatively cover the Sale
- **WHEN** multiple effective received standard cash returns cumulatively cover every dispatched line and all contributing settlements are final
- **THEN** completing the final required settlement SHALL archive the source Sale exactly once

#### Scenario: Repair or replacement completes
- **WHEN** a standard repair/replacement or cannot-process return completes approved customer dispatch
- **THEN** its return document SHALL complete without archiving the source Sale or changing its financial amounts
- **AND** service history SHALL remain visible without presenting those goods as a completed cash return

#### Scenario: Fully unpaid sale returns in full
- **WHEN** full standard cash-return coverage settles entirely through debt reduction
- **THEN** the Sale SHALL archive with zero outstanding obligation and no cash payout

### Requirement: Settlement archival does not move inventory twice
Completing or archiving a standard Sales Return SHALL preserve its held, released, or customer-dispatched custody state. Settlement and archival MUST NOT repeat receiving, release goods for sale, or create duplicate stock, serial, or payment effects. Explicit inspection release SHALL remain separate from financial completion.

#### Scenario: Cash refund follows receiving
- **WHEN** receiving has placed goods and their serials in held custody before cash-return settlement approval
- **THEN** settlement completion and source Sale archival SHALL preserve that custody and unavailable serial state
- **AND** no second receiving inventory movement SHALL be created

#### Scenario: Settlement approval is retried
- **WHEN** an already completed settlement or archival action is retried
- **THEN** source Sale archival SHALL remain idempotent
- **AND** inventory and return-payment effects SHALL not be duplicated

#### Scenario: Completed return later passes inspection
- **WHEN** eligible held goods pass a separately authorized inspection after financial completion
- **THEN** sellable release SHALL occur once without reopening settlement or repeating receiving

### Requirement: Archival coverage uses effective return lifecycle state
Only physically received standard cash-return quantities with final settlements SHALL contribute to standard source Sale archival coverage. Draft, awaiting-approval, rejected, archived-invalid, unreceived, unresolved, repair/replacement, and cannot-process quantities MUST NOT archive a Sale. POS-corrected active quantities MUST NOT be combined with standard original-dispatch coverage.

#### Scenario: Unreceived return claims full quantity
- **WHEN** submitted return details equal the dispatched quantity but the return has not been received
- **THEN** the source Sale SHALL not be archived from those claimed quantities

#### Scenario: Another contributing settlement remains pending
- **WHEN** received cash-return quantities fully cover the Sale but one contributing settlement is unresolved
- **THEN** the source Sale SHALL remain unarchived

#### Scenario: Over-return on one line hides a shortage on another
- **WHEN** total claimed quantities equal the Sale total but a dispatched line lacks full valid cash-return coverage
- **THEN** the source Sale SHALL remain unarchived

#### Scenario: POS return completes
- **WHEN** POS final approval completes linked Sales Returns
- **THEN** established POS active-quantity correction and archival rules SHALL continue without standard per-item settlements or inspection prerequisites

#### Scenario: Historical mixed workflow cannot prove coverage
- **WHEN** a source Sale contains conflicting standard coverage and POS-mutated active quantities
- **THEN** unsafe new archival SHALL be blocked with an audit reason instead of combining the quantities or rewriting history

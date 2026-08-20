## ADDED Requirements

### Requirement: Fully completed standard Sales Return archives its source Sale
When cumulative physically received standard Sales Return quantities fully cover the source Sale's dispatched quantities and final settlement is completed, the system SHALL mark the source Sale returned and archive it with actor, time, and Sales Return reference evidence. Archival MUST NOT require POS-only mutation of Sale detail or dispatch quantities.

#### Scenario: Full standard cash refund completes
- **WHEN** a standard Sales Return has received the full dispatched quantity and its final cash-refund settlement becomes completed
- **THEN** the source Sale SHALL have `RETURNED` status
- **AND** it SHALL persist `archived_at`, `archived_by`, and an audit note containing the Sales Return reference

#### Scenario: Partial standard return completes settlement
- **WHEN** cumulative received and completed standard Sales Returns cover less than the source Sale's dispatched quantity
- **THEN** the source Sale SHALL remain unarchived and returned-partially

#### Scenario: Multiple standard returns cumulatively cover the Sale
- **WHEN** multiple effective received Sales Returns cumulatively cover the full dispatched quantity and their required settlement is complete
- **THEN** completing the final required settlement SHALL archive the source Sale exactly once

### Requirement: Settlement archival does not move inventory twice
Completing or archiving a received Sales Return SHALL not repeat the stock, serial, or transaction movement already performed during receiving.

#### Scenario: Cash refund follows receiving
- **WHEN** receiving has restored product stock and activated the returned serial before cash-refund settlement approval
- **THEN** settlement completion and source Sale archival SHALL preserve those resulting stock and serial values
- **AND** no second return inventory transaction SHALL be created

#### Scenario: Settlement approval is retried
- **WHEN** an already completed settlement or archival action is retried
- **THEN** source Sale archival SHALL remain idempotent
- **AND** inventory and return-payment effects SHALL not be duplicated

### Requirement: Archival coverage uses effective return lifecycle state
Only received Sales Returns in an effective awaiting-settlement or completed state SHALL contribute to source Sale return coverage. Draft, awaiting-approval, rejected, archived-invalid, or unreceived return quantities MUST NOT archive a Sale.

#### Scenario: Unreceived return claims full quantity
- **WHEN** submitted return details equal the dispatched quantity but the return has not been received
- **THEN** the source Sale SHALL not be archived from those claimed quantities


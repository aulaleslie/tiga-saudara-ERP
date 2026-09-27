## ADDED Requirements

### Requirement: Mutable POS transaction cancellation MUST require explicit cancel authority
The system SHALL treat POS transaction cancellation as a destructive action separate from draft ownership. A mutable POS transaction MUST be cancellable only when the acting user has direct `pos.void` authority or presents a valid approval token issued through the supervisor approval flow.

#### Scenario: User with direct void permission cancels mutable transaction
- **WHEN** a user with `pos.void` attempts to cancel a POS transaction in `DRAFT` or `LOADED` status
- **THEN** the system MUST cancel the transaction without requiring an approval token

#### Scenario: User without direct void permission cannot cancel mutable transaction immediately
- **WHEN** a user without `pos.void` attempts to cancel a POS transaction in `DRAFT` or `LOADED` status without an approval token
- **THEN** the system MUST reject immediate cancellation
- **AND** the system MUST require supervisor approval before the cancellation can proceed

#### Scenario: Approved token authorizes cancellation
- **WHEN** a user retries POS transaction cancellation with a valid approval token issued for that cancel action
- **THEN** the system MUST cancel the mutable transaction

### Requirement: POS transaction cancel approval MUST mirror the clear-cart interaction model
The system SHALL expose transaction cancellation approval through the same interaction pattern used for clear-cart approval: create a supervisor approval request, wait for a decision, and continue or cancel the pending action from the UI once the decision is available.

#### Scenario: Cancel request enters approval flow
- **WHEN** a user without direct `pos.void` authority initiates a mutable POS transaction cancel action from the transaction list or detail view
- **THEN** the system MUST create a supervisor approval request for transaction cancellation
- **AND** the UI MUST reflect that approval is pending

#### Scenario: Approved cancel request can continue or be discarded
- **WHEN** a transaction cancel approval request is approved
- **THEN** the UI MUST offer a continue-or-cancel state equivalent to the clear-cart approval interaction
- **AND** the approved cancellation MUST execute only when the user explicitly continues the action with the issued token

#### Scenario: Rejected or discarded cancel request does not cancel transaction
- **WHEN** a transaction cancel approval request is rejected, expired, or explicitly discarded by the requester
- **THEN** the system MUST leave the POS transaction in its prior mutable status
- **AND** the UI MUST clear the pending cancellation state without applying the destructive action

### Requirement: Immutable POS transactions MUST remain non-cancellable
The system SHALL reject cancellation attempts for immutable POS transactions even when the acting user has direct void authority or an approval token.

#### Scenario: Completed transaction cannot be cancelled with void authority
- **WHEN** a user with `pos.void` attempts to cancel a completed POS transaction
- **THEN** the system MUST reject the cancellation request

#### Scenario: Completed transaction cannot be cancelled with approval token
- **WHEN** a user presents an approval token to cancel a completed POS transaction
- **THEN** the system MUST reject the cancellation request

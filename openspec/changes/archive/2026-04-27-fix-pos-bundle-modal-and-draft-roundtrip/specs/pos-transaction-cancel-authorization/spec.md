## MODIFIED Requirements

### Requirement: Mutable POS transaction cancellation MUST require explicit cancel authority

The system SHALL treat POS transaction cancellation as a destructive action separate from draft ownership. A POS transaction MUST be cancellable only when its status is `DRAFT` and the acting user has direct `pos.void` authority or presents a valid approval token issued through the supervisor approval flow. Transactions in `LOADED` status SHALL NOT be cancellable from any UI or API path because they are currently held in an active cart.

#### Scenario: User with direct void permission cancels a draft transaction
- **WHEN** a user with `pos.void` attempts to cancel a POS transaction in `DRAFT` status
- **THEN** the system MUST cancel the transaction without requiring an approval token

#### Scenario: User without direct void permission cannot cancel a draft transaction immediately
- **WHEN** a user without `pos.void` attempts to cancel a POS transaction in `DRAFT` status without an approval token
- **THEN** the system MUST reject immediate cancellation
- **AND** the system MUST require supervisor approval before the cancellation can proceed

#### Scenario: Approved token authorizes cancellation of a draft transaction
- **WHEN** a user retries POS transaction cancellation with a valid approval token issued for that cancel action and the transaction is in `DRAFT` status
- **THEN** the system MUST cancel the transaction

#### Scenario: Loaded transaction cannot be cancelled via direct void permission
- **WHEN** a user with `pos.void` attempts to cancel a POS transaction in `LOADED` status
- **THEN** the system MUST reject the cancellation request with a not-cancellable error
- **AND** the transaction status MUST remain `LOADED`

#### Scenario: Loaded transaction cannot be cancelled with an approval token
- **WHEN** a user presents an approval token to cancel a POS transaction in `LOADED` status
- **THEN** the system MUST reject the cancellation request with a not-cancellable error
- **AND** the transaction status MUST remain `LOADED`

#### Scenario: Transactions list hides the cancel button for loaded transactions
- **WHEN** the POS transactions list renders a row whose status is `LOADED`
- **THEN** the row MUST NOT expose a "Batalkan" cancel control
- **AND** the row MAY still expose other allowed actions such as detail or reprint

### Requirement: POS transaction cancel approval MUST mirror the clear-cart interaction model

The system SHALL expose transaction cancellation approval through the same interaction pattern used for clear-cart approval: create a supervisor approval request, wait for a decision, and continue or cancel the pending action from the UI once the decision is available. Approval requests SHALL only be created for transactions in `DRAFT` status.

#### Scenario: Cancel request enters approval flow for a draft transaction
- **WHEN** a user without direct `pos.void` authority initiates a POS transaction cancel action against a transaction in `DRAFT` status from the transaction list or detail view
- **THEN** the system MUST create a supervisor approval request for transaction cancellation
- **AND** the UI MUST reflect that approval is pending

#### Scenario: Approved cancel request can continue or be discarded
- **WHEN** a transaction cancel approval request is approved
- **THEN** the UI MUST offer a continue-or-cancel state equivalent to the clear-cart approval interaction
- **AND** the approved cancellation MUST execute only when the user explicitly continues the action with the issued token

#### Scenario: Rejected or discarded cancel request does not cancel transaction
- **WHEN** a transaction cancel approval request is rejected, expired, or explicitly discarded by the requester
- **THEN** the system MUST leave the POS transaction in its prior status
- **AND** the UI MUST clear the pending cancellation state without applying the destructive action

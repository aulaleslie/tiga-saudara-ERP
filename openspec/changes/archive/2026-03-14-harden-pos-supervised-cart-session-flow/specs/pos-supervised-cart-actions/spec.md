## ADDED Requirements

### Requirement: Restricted Cart Mutations MUST Require Supervisory Approval For Non-Authorized Users
The POS system SHALL require supervisory approval before executing `clear cart`, `remove line`, or `reduce quantity` actions when the acting user lacks direct permission for the action.

#### Scenario: Non-authorized user requests clear cart
- **WHEN** a Floor Staff or Cashier Staff user attempts to clear the cart without direct clear permission
- **THEN** the system MUST create an approval request and MUST NOT clear the cart immediately

#### Scenario: Non-authorized user requests line removal
- **WHEN** a Floor Staff or Cashier Staff user attempts to remove an item line without direct remove permission
- **THEN** the system MUST create an approval request and MUST NOT remove the line immediately

#### Scenario: Non-authorized user requests quantity reduction
- **WHEN** a Floor Staff or Cashier Staff user submits a lower quantity than current quantity without direct reduce permission
- **THEN** the system MUST create an approval request and MUST NOT apply the reduced quantity immediately

#### Scenario: Authorized manager executes directly
- **WHEN** a Store Manager user performs clear cart, remove line, or reduce quantity with required direct permission
- **THEN** the system MUST execute the action immediately without creating approval request

### Requirement: Approval Request State MUST Be Deterministic And Queryable
The system SHALL expose deterministic approval states so users can explicitly check whether a submitted request is still pending, approved, or rejected.

#### Scenario: Pending request remains actionable for re-check
- **WHEN** a user checks approval status for a request that is still pending
- **THEN** the response MUST return `pending`, MUST NOT return execution token, and MUST allow user to check again later

#### Scenario: Approved request issues execution token
- **WHEN** a supervisor approves a pending request and the requester checks status
- **THEN** the response MUST return `approved` and MUST include a one-time execution token for the requested action

#### Scenario: Rejected request closes without mutation
- **WHEN** a supervisor rejects a pending request and the requester checks status
- **THEN** the response MUST return `rejected` and MUST keep cart state unchanged

### Requirement: Approved Restricted Actions MUST Require Explicit Final Confirmation
The POS system SHALL require explicit user confirmation after approval before mutating the cart.

#### Scenario: User confirms approved action
- **WHEN** the request status is approved and the requester chooses `Lanjutkan`
- **THEN** the system MUST consume the execution token exactly once and MUST apply the approved mutation

#### Scenario: User cancels approved action
- **WHEN** the request status is approved and the requester chooses `Batalkan`
- **THEN** the system MUST NOT apply the mutation and MUST leave cart state unchanged

#### Scenario: Token replay is rejected
- **WHEN** a consumed execution token is reused
- **THEN** the system MUST reject the request and MUST NOT apply any cart mutation

### Requirement: Price Override MUST Follow Role-Aware Supervisory Governance
The POS system SHALL allow direct price override only for authorized manager-level users and SHALL require approval workflow for non-authorized users.

#### Scenario: Non-authorized user requests price change
- **WHEN** a Floor Staff or Cashier Staff user attempts to lower or alter sales price without direct override permission
- **THEN** the system MUST create approval request and MUST NOT apply new price until approval is confirmed and executed

#### Scenario: Authorized manager overrides price directly
- **WHEN** a Store Manager user with price override permission updates item sales price
- **THEN** the system MUST apply the new price immediately and MUST record audit metadata for actor and timestamp

### Requirement: Supervisory Queue MUST Resolve Pending Requests
Users with supervisory approval permission SHALL be able to review, approve, and reject pending POS approval requests.

#### Scenario: Supervisor approves request
- **WHEN** a supervisor approves a pending request from queue
- **THEN** the request status MUST become approved and MUST be available for requester status check

#### Scenario: Supervisor rejects request
- **WHEN** a supervisor rejects a pending request from queue
- **THEN** the request status MUST become rejected and MUST prevent the requested mutation from executing

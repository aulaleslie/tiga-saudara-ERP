## ADDED Requirements

### Requirement: Checkout finalization SHALL require checkout-authorized POS bundles
The `POST /pos/sell/checkout/finalize` flow SHALL only complete for users whose POS bundle includes checkout authority. Supported `cashier` and `manager` bundles SHALL be allowed to finalize checkout, but cashier SHALL require an active terminal assignment while manager SHALL NOT. Supported `floor staff` SHALL NOT be allowed to finalize checkout even when they can access the POS shell, save drafts, or load drafts.

#### Scenario: Floor staff finalize attempt is rejected
- **WHEN** a user in the supported `floor staff` bundle submits checkout finalization
- **THEN** the system MUST reject the request
- **AND** the cart or payment-chain state MUST remain recoverable for an authorized user to continue

#### Scenario: Cashier finalizes prepared transaction
- **WHEN** a user in the supported `cashier` bundle completes the payment chain for a prepared transaction from an active terminal-assigned session
- **THEN** the system MUST finalize checkout successfully

#### Scenario: Cashier finalize attempt is rejected without terminal assignment
- **WHEN** a user in the supported `cashier` bundle submits checkout finalization from an active session that has no terminal assigned
- **THEN** the system MUST reject the request
- **AND** the cart or payment-chain state MUST remain recoverable for an authorized user to continue

#### Scenario: Manager finalizes during operational intervention
- **WHEN** a user in the supported `manager` bundle completes checkout for an authorized transaction, including from a session without terminal assignment
- **THEN** the system MUST allow finalization without relying on owner-only bypass

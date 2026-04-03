## ADDED Requirements

### Requirement: Staged payment flow SHALL require checkout-authorized POS bundles
The staged payment flow SHALL only be available to POS users whose bundle includes checkout authority. Supported `cashier` and `manager` bundles SHALL be allowed to begin, recover, and continue staged payment, but cashier SHALL require an active terminal assignment while manager SHALL NOT. Supported `floor staff` SHALL NOT be allowed to begin, recover, or continue staged payment even if they can access the POS shell and draft handoff flow.

#### Scenario: Floor staff cannot begin staged payment
- **WHEN** a user in the supported `floor staff` bundle opens the POS shell and attempts to start staged payment
- **THEN** the UI MUST keep payment actions unavailable
- **AND** the backend MUST reject staged payment entry points

#### Scenario: Cashier can continue staged payment after reload
- **WHEN** a user in the supported `cashier` bundle has an active terminal-assigned session, has already committed at least one payment stage, and reloads the browser
- **THEN** the system MUST recover the payment chain and allow the user to continue staged payment

#### Scenario: Cashier cannot begin staged payment without terminal assignment
- **WHEN** a user in the supported `cashier` bundle has checkout authority but the active session has no terminal assigned
- **THEN** the UI MUST keep staged payment controls unavailable
- **AND** the backend MUST reject staged payment entry points

#### Scenario: Manager can enter staged payment for operational intervention
- **WHEN** a user in the supported `manager` bundle enters the POS shell for an authorized transaction, including from a session without terminal assignment
- **THEN** the system MUST allow the same staged payment behaviors available to cashier

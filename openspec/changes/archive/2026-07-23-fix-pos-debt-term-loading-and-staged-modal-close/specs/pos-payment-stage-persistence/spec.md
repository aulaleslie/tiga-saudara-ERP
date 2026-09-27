## ADDED Requirements

### Requirement: Staged-payment modal dismissal SHALL preserve recoverable state
Ordinary staged-payment modal dismissal controls, including the header × and footer **Batal**, SHALL close the modal without deleting or resetting the in-progress session payment chain. All ordinary dismissal controls SHALL have consistent behavior.

#### Scenario: Header close preserves an in-progress chain
- **WHEN** a cashier clicks the header × while the staged-payment modal has an in-progress payment chain and no request is processing
- **THEN** the modal MUST close
- **AND** the session payment chain MUST remain unchanged
- **AND** reopening or reloading MUST recover the committed payment stages and remainder

#### Scenario: Footer cancel preserves an in-progress chain
- **WHEN** a cashier clicks **Batal** while the staged-payment modal has an in-progress payment chain and no request is processing
- **THEN** the modal MUST close with the same state-preservation behavior as the header ×

#### Scenario: Dismissal is unavailable during processing
- **WHEN** a staged-payment submission or checkout finalization is processing
- **THEN** every ordinary dismissal control MUST be unavailable until processing ends

### Requirement: Payment-chain reset SHALL require an explicit destructive action
The POS SHALL expose payment-chain deletion only through a clearly labelled destructive action that is distinct from ordinary modal dismissal and requires cashier confirmation. The client SHALL update local state only after the server confirms the reset.

#### Scenario: Cashier confirms explicit payment-chain reset
- **WHEN** a cashier invokes the explicit payment-chain reset action and confirms the warning
- **THEN** the POS MUST delete the matching session payment chain
- **AND** clear the matching local staged-payment and debt state
- **AND** close the staged-payment modal

#### Scenario: Cashier declines explicit payment-chain reset
- **WHEN** a cashier invokes the explicit payment-chain reset action but declines confirmation
- **THEN** the POS MUST keep the modal open
- **AND** MUST leave both the session payment chain and local staged-payment state unchanged

#### Scenario: Payment-chain reset fails
- **WHEN** the confirmed payment-chain reset request fails
- **THEN** the POS MUST keep the current modal and local state intact
- **AND** display an actionable reset error

#### Scenario: Reset is unavailable during processing
- **WHEN** a staged-payment submission or checkout finalization is processing
- **THEN** the explicit payment-chain reset action MUST be unavailable until processing ends

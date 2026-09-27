## MODIFIED Requirements

### Requirement: Authorized Users MUST Be Able To Clear Cart Regardless Of Transaction State
The POS system SHALL allow users with Super Admin authority or users with direct `pos.cart.clear` permission to execute the `clear cart` action even when an active transaction is loaded. This action SHALL unload the transaction (reverting status to DRAFT) while emptying the cart session.

#### Scenario: Super Admin clears cart with loaded transaction
- **WHEN** a Super Admin user attempts to clear the cart while an active transaction is loaded
- **THEN** the system MUST unload the transaction (status reverts to DRAFT), MUST clear the cart immediately, and MUST NOT return a `TRANSACTION_EMPTY_BLOCKED` error

#### Scenario: Authorized user clears cart with loaded transaction
- **WHEN** a user with direct `pos.cart.clear` permission attempts to clear the cart while an active transaction is loaded
- **THEN** the system MUST unload the transaction (status reverts to DRAFT), MUST clear the cart immediately, and MUST NOT return a `TRANSACTION_EMPTY_BLOCKED` error

#### Scenario: Non-authorized user is still blocked from clearing loaded transaction
- **WHEN** a user without `pos.cart.clear` permission attempts to clear the cart while an active transaction is loaded
- **THEN** the system MUST return a `TRANSACTION_EMPTY_BLOCKED` error and MUST NOT unload the transaction or clear the cart

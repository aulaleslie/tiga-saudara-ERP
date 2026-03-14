## ADDED Requirements

### Requirement: Super Admins MUST Be Able To Clear Cart Regardless Of Transaction State
The POS system SHALL allow users with Super Admin authority to execute the `clear cart` action even when an active transaction is loaded, effectively unloading the transaction while emptying the cart session.

#### Scenario: Super Admin clears cart with loaded transaction
- **WHEN** a Super Admin user attempts to clear the cart while an active transaction is loaded
- **THEN** the system MUST unload the transaction and MUST clear the cart immediately without returning a `TRANSACTION_EMPTY_BLOCKED` error

### Requirement: Cart Clear Action UI MUST Maintain Label Consistency
The POS UI SHALL ensure that the "Kosongkan Keranjang" button maintains its intended label during and after action cycles, correctly resetting to its original text upon failure or completion.

#### Scenario: Cart clear action finishes or fails
- **WHEN** the cart clear action is triggered and subsequently completes or encounters an error
- **THEN** the system MUST restore the button label to "Kosongkan Keranjang"

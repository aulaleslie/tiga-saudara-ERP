## MODIFIED Requirements

### Requirement: Save And Open New MUST Support Floor-To-Cashier Draft Handoff
The POS `Simpan dan Buka Baru` flow SHALL support permission-driven draft handoff. Any user with POS shell access and `pos.transactions.save` MUST be able to save a valid draft for handoff, and any user with POS shell access plus `pos.transactions.load` MUST be able to reopen that draft for continuation. Payment completion remains subject to explicit checkout-payment permission.

#### Scenario: Authorized handoff user saves draft and resets cart
- **WHEN** a user with `pos.sell` and `pos.transactions.save` clicks `Simpan dan Buka Baru` on a valid cart
- **THEN** the system MUST create or update a draft transaction record
- **AND** the system MUST clear the active cart for new entry

#### Scenario: Authorized continuation user reopens draft transaction
- **WHEN** a user with `pos.sell` and `pos.transactions.load` opens a POS transaction by transaction number for a draft record
- **THEN** the system MUST load the draft cart content for continuation in the POS shell
- **AND** later payment completion MUST still require `pos.checkout.payment`

### Requirement: POS Transaction Mutability MUST Be Limited To Draft Status
Only draft POS transactions SHALL allow cart mutation actions, and completed transactions SHALL be immutable.

#### Scenario: Draft transaction is editable
- **WHEN** a draft transaction is loaded in POS sell flow
- **THEN** permitted cart updates MUST be allowed according to permission and approval policy

#### Scenario: Completed transaction is not editable
- **WHEN** a completed transaction is opened from POS transaction list
- **THEN** the system MUST prevent cart mutation actions and MUST indicate transaction is finalized

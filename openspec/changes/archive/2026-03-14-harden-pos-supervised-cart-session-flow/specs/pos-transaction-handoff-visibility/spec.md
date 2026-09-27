## ADDED Requirements

### Requirement: Save And Open New MUST Support Floor-To-Cashier Draft Handoff
The POS `Simpan dan Buka Baru` flow SHALL persist a draft transaction that can be reopened by transaction number for continuation at cashier.

#### Scenario: Floor staff saves draft and resets cart
- **WHEN** Floor Staff clicks `Simpan dan Buka Baru` on a valid cart
- **THEN** the system MUST create or update a draft transaction record and MUST clear the active cart for new entry

#### Scenario: Cashier reopens draft transaction
- **WHEN** Cashier Staff opens POS transaction by transaction number for a draft record
- **THEN** the system MUST load draft cart content for continuation and payment completion

### Requirement: POS Transaction Mutability MUST Be Limited To Draft Status
Only draft POS transactions SHALL allow cart mutation actions, and completed transactions SHALL be immutable.

#### Scenario: Draft transaction is editable
- **WHEN** a draft transaction is loaded in POS sell flow
- **THEN** permitted cart updates MUST be allowed according to role and approval policy

#### Scenario: Completed transaction is not editable
- **WHEN** a completed transaction is opened from POS transaction list
- **THEN** the system MUST prevent cart mutation actions and MUST indicate transaction is finalized

### Requirement: Transactions List Default Scope MUST Include All Statuses
When user does not apply status filter on POS transaction list, the system SHALL show all transaction statuses including completed.

#### Scenario: No status filter selected
- **WHEN** user loads `/pos/transactions` and status filter is empty
- **THEN** the list response MUST include draft and completed transactions in the current setting scope

#### Scenario: Status filter selected
- **WHEN** user selects one or more statuses and reloads list
- **THEN** the list response MUST apply selected status filter values only

### Requirement: Checkout Completion MUST Be Represented In POS Transaction History
The POS checkout flow SHALL ensure completed sales are represented as completed POS transactions.

#### Scenario: Checkout from loaded draft
- **WHEN** cashier completes payment for a loaded draft transaction
- **THEN** the corresponding transaction status MUST transition to completed and remain visible in transaction list

#### Scenario: Checkout without preloaded draft
- **WHEN** cashier completes payment from active cart that was not loaded from existing draft
- **THEN** the system MUST create a completed POS transaction record linked to the checkout sale

## MODIFIED Requirements

### Requirement: Save And Open New MUST Support Floor-To-Cashier Draft Handoff
The POS `Simpan dan Buka Baru` flow SHALL persist a draft transaction that can be reopened for continuation at cashier. The supported `floor staff` and `cashier` bundles SHALL both be able to save and load draft transactions for handoff, while only bundles with checkout authority SHALL be allowed to continue into payment completion.

#### Scenario: Floor staff saves draft and resets cart
- **WHEN** a `floor staff` user triggers `Simpan dan Buka Baru` on a valid cart
- **THEN** the system MUST create or update a draft transaction record
- **AND** the system MUST clear the active cart for new entry

#### Scenario: Cashier reopens draft transaction
- **WHEN** a `cashier` user opens a POS transaction by transaction number for a draft record
- **THEN** the system MUST load draft cart content for continuation
- **AND** the system MUST allow payment completion if the user also has checkout authority

### Requirement: POS Transaction Mutability MUST Be Limited To Draft Status
Only draft POS transactions SHALL allow cart mutation actions, and completed transactions SHALL be immutable.

#### Scenario: Draft transaction is editable
- **WHEN** a draft transaction is loaded in POS sell flow
- **THEN** permitted cart updates MUST be allowed according to explicit permission and approval policy

#### Scenario: Completed transaction is not editable
- **WHEN** a completed transaction is opened from POS transaction list
- **THEN** the system MUST prevent cart mutation actions
- **AND** the system MUST indicate the transaction is finalized

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
- **WHEN** a checkout-authorized user completes payment for a loaded draft transaction
- **THEN** the corresponding transaction status MUST transition to completed
- **AND** the transaction MUST remain visible in transaction list

#### Scenario: Checkout without preloaded draft
- **WHEN** a checkout-authorized user completes payment from active cart that was not loaded from an existing draft
- **THEN** the system MUST create a completed POS transaction record linked to the checkout sale

## ADDED Requirements

### Requirement: Manager bundle SHALL be able to override draft ownership through explicit transaction override authority
The supported `manager` bundle SHALL be able to view and take over saved POS draft transactions when it includes the documented transaction override authority. Cashier and floor-staff bundles SHALL remain limited to their normal handoff scope unless explicitly granted the same override permission.

#### Scenario: Manager loads another user's draft
- **WHEN** a manager has transaction override authority and attempts to load a draft created by another user
- **THEN** the system MUST allow the load operation

#### Scenario: Cashier without override authority cannot load arbitrary draft
- **WHEN** a cashier or floor-staff user attempts to load a draft outside their allowed ownership scope without transaction override authority
- **THEN** the system MUST reject the request with an authorization or conflict error

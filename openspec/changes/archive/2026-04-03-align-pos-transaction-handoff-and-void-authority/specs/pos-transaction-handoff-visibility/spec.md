## MODIFIED Requirements

### Requirement: Save And Open New MUST Support Floor-To-Cashier Draft Handoff
The POS `Simpan dan Buka Baru` flow SHALL persist a draft transaction that can be reopened for continuation at cashier. Any user in the same setting with POS shell access and `pos.transactions.load` MUST be able to load any mutable POS draft for continuation, while only bundles with checkout authority SHALL be allowed to continue into payment completion.

#### Scenario: Floor staff saves draft and resets cart
- **WHEN** a `floor staff` user triggers `Simpan dan Buka Baru` on a valid cart
- **THEN** the system MUST create or update a draft transaction record
- **AND** the system MUST clear the active cart for new entry

#### Scenario: Cashier loads another user's mutable draft
- **WHEN** a `cashier` user with `pos.transactions.load` opens a mutable POS transaction created by another user in the same setting
- **THEN** the system MUST load the draft cart content for continuation
- **AND** the system MUST allow payment completion only if the user also has checkout authority

#### Scenario: User without load permission cannot load a mutable draft
- **WHEN** a user attempts to load a mutable POS transaction without `pos.transactions.load`
- **THEN** the system MUST reject the load request with an authorization error

### Requirement: POS Transaction Mutability MUST Be Limited To Draft Status
Only mutable POS transactions SHALL allow handoff continuation and cancellation actions, and completed transactions SHALL be immutable.

#### Scenario: Mutable transaction is loadable
- **WHEN** a POS transaction is in `DRAFT` or `LOADED` status
- **THEN** the system MUST allow the transaction to be loaded according to explicit load permission rules

#### Scenario: Completed transaction is not editable
- **WHEN** a completed transaction is opened from POS transaction list
- **THEN** the system MUST prevent cart mutation actions
- **AND** the system MUST indicate transaction is finalized

## REMOVED Requirements

### Requirement: Manager bundle SHALL be able to override draft ownership through explicit transaction override authority
**Reason**: Draft loading is being redefined as a collaboration permission controlled by `pos.transactions.load`, not by owner-only mutation rules plus manager takeover authority.
**Migration**: Treat `pos.transactions.load` as the requirement for loading any mutable draft in the same setting, and reserve `pos.transactions.edit.any` for any remaining administrative behaviors outside normal draft loading.

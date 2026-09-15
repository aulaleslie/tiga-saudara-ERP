## MODIFIED Requirements

### Requirement: Authorized origin users can create and edit stock-transfer requests
The system SHALL allow a user with `stockTransfers.create` to create a `DRAFT` stock-transfer request with an active origin owned by the active tenant, one explicit stock condition, and at least one valid product quantity while allowing destination to remain unset. The system SHALL require `stockTransfers.edit` to discover, open, edit, or submit an existing transfer, SHALL allow mutation only while its current lifecycle state is `DRAFT` or `PENDING`, and SHALL require the origin to belong to the active tenant. A material mutation to a `PENDING` transfer SHALL atomically return it to `DRAFT` and require explicit resubmission; a no-op save SHALL NOT change lifecycle state, revision, lines, or history. A destination accepted through the transfer form's nested location selector MUST remain synchronized with the parent form through the subsequent save or submission request without clearing existing product or serial intent.

#### Scenario: Save a draft without destination
- **WHEN** an authorized user saves a new transfer with a valid active origin, one transfer mode, and at least one valid product quantity but no destination
- **THEN** the system atomically creates the transfer and its lines in `DRAFT` status with no destination

#### Scenario: Save a draft with destination
- **WHEN** an authorized user saves a draft with a destination
- **THEN** the system authoritatively validates that the destination is active, distinct from the origin, and compatible with the origin before storing it

#### Scenario: Add destination to an existing serialized draft
- **WHEN** an authorized origin user opens a destination-less `DRAFT` containing serialized product intent, selects a valid destination through the nested location selector, and saves
- **THEN** the selected destination and unchanged serialized intent persist atomically without the destination reverting to unset or the product rows being cleared

#### Scenario: Discover a saved draft edit action
- **WHEN** an origin-tenant user with `stockTransfers.edit` views the transfer list containing a `DRAFT` or `PENDING` transfer
- **THEN** the system presents an edit action for that transfer

#### Scenario: Hide edit action without permission
- **WHEN** a user without `stockTransfers.edit` views a `DRAFT` or `PENDING` transfer in the list
- **THEN** the system does not present its edit action

#### Scenario: Submit a complete draft for approval
- **WHEN** an authorized origin-tenant editor submits a `DRAFT` transfer with valid distinct origin and destination locations, one transfer mode, and at least one valid product quantity
- **THEN** the system revalidates the complete request and atomically transitions it to `PENDING`

#### Scenario: Reject submission without destination
- **WHEN** an authorized user attempts to submit a `DRAFT` transfer whose destination is unset or no longer valid
- **THEN** the system keeps the transfer and lines unchanged in `DRAFT` status and returns an actionable validation error

#### Scenario: Edit a saved draft
- **WHEN** an authorized origin-tenant user materially updates a `DRAFT` transfer
- **THEN** the system saves the complete updated request atomically, advances its revision, records a `DRAFT` to `DRAFT` edit, and keeps it in `DRAFT`

#### Scenario: Revise a pending transfer
- **WHEN** an authorized origin-tenant user materially updates a `PENDING` transfer
- **THEN** the system atomically saves the revised request, advances its revision, records a `PENDING` to `DRAFT` transition, and requires explicit resubmission before approval

#### Scenario: Open pending edit without mutation
- **WHEN** an authorized user opens a `PENDING` transfer for editing but does not save a material change
- **THEN** the system leaves its status, revision, lines, and history unchanged

#### Scenario: Save an unchanged editable transfer
- **WHEN** an authorized user submits an edit whose normalized header and line state is identical to the persisted transfer
- **THEN** the system leaves its status, revision, lines, and history unchanged

#### Scenario: Reject a stale concurrent edit
- **WHEN** approval or another edit advances the persisted revision before an editor saves
- **THEN** the system rejects the stale save without partially changing the transfer, lines, status, revision, or history

#### Scenario: Block destination or unrelated tenant editing
- **WHEN** a user whose active tenant does not own the transfer origin directly requests the edit, update, or submission endpoint
- **THEN** the system rejects the request without changing the transfer or its lines

#### Scenario: Block mutation after approval or dispatch
- **WHEN** a user attempts to edit a transfer that is approved, archived, dispatched, received, awaiting return, return-dispatched, or completed
- **THEN** the system rejects the mutation regardless of whether the user can see the transfer

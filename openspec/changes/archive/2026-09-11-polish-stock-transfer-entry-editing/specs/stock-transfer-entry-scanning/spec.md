## MODIFIED Requirements

### Requirement: Authorized origin users can create and edit stock-transfer requests
The system SHALL allow a user with `stockTransfers.create` to create a `DRAFT` stock-transfer request with an active origin owned by the active tenant, one explicit stock condition, and at least one valid product quantity while allowing destination to remain unset. The system SHALL require `stockTransfers.edit` to discover, open, edit, or submit an existing transfer, SHALL allow mutation only while its current lifecycle state is `DRAFT` or `PENDING`, and SHALL require the origin to belong to the active tenant. A material mutation to a `PENDING` transfer SHALL atomically return it to `DRAFT` and require explicit resubmission; a no-op save SHALL NOT change lifecycle state, revision, lines, or history.

#### Scenario: Save a draft without destination
- **WHEN** an authorized user saves a new transfer with a valid active origin, one transfer mode, and at least one valid product quantity but no destination
- **THEN** the system atomically creates the transfer and its lines in `DRAFT` status with no destination

#### Scenario: Save a draft with destination
- **WHEN** an authorized user saves a draft with a destination
- **THEN** the system authoritatively validates that the destination is active, distinct from the origin, and compatible with the origin before storing it

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

### Requirement: Each editable transfer SHALL use one explicit stock condition
The system SHALL require one form-wide stock condition, good stock or breakage stock, when a new transfer is created. The create form SHALL expose the condition as an accessible segmented choice, while every persisted transfer SHALL treat its condition as immutable and SHALL render it only as read-only context during ordinary editing. Product lookup, quantities, serials, mapping, draft validation, and submission SHALL use only the selected or persisted condition and SHALL reject mixed-condition or condition-changing payloads.

#### Scenario: Choose good-stock mode during creation
- **WHEN** an operator selects good-stock mode on a new transfer
- **THEN** the segmented control visibly and semantically identifies good stock as selected and product lookup accepts only saleable stock and serials

#### Scenario: Choose breakage-stock mode during creation
- **WHEN** an operator selects breakage-stock mode on a new transfer
- **THEN** the segmented control visibly and semantically identifies breakage stock as selected and product lookup accepts only available broken stock and serials

#### Scenario: Operate the condition choice accessibly
- **WHEN** a keyboard or assistive-technology user interacts with the create form
- **THEN** the system exposes labeled form controls with keyboard operation, visible focus, and selected, disabled, and validation states that do not rely on color alone

#### Scenario: Change creation condition after entering rows
- **WHEN** an operator attempts to switch condition on an unsaved creation form after entering product or serial rows
- **THEN** the system requires confirmation before clearing all rows and applying the new condition, and cancellation preserves the prior condition and rows

#### Scenario: Hydrate an editable transfer
- **WHEN** an operator opens a persisted `DRAFT` or `PENDING` transfer
- **THEN** the form displays its persisted condition as read-only context and hydrates product rows using that condition without exposing a condition toggle

#### Scenario: Attempt to change condition on an existing transfer
- **WHEN** a crafted Livewire or HTTP request submits a stock condition different from the persisted transfer
- **THEN** authoritative validation rejects the request without changing the transfer, its lines, revision, status, or history

#### Scenario: Submit a mixed-condition payload
- **WHEN** a client submits good and broken quantities or serials that conflict with the transfer's selected or persisted condition
- **THEN** authoritative validation rejects the request without partially changing the transfer or its lines

#### Scenario: View a historical mixed-condition transfer
- **WHEN** a historical transfer contains both good and broken stock buckets
- **THEN** the system preserves and renders its historical contents as view-only without offering ordinary editing or silently rewriting its condition or inventory history

### Requirement: Stock-context changes SHALL reset product rows deterministically
The system SHALL clear stock-transfer product rows when the selected origin or creation-time stock condition actually changes, because those values determine stock and serial eligibility, and SHALL preserve rows when only the destination changes. Persisted transfers SHALL NOT permit origin or condition changes through ordinary editing.

#### Scenario: Change origin with entered rows during creation
- **WHEN** the operator changes or clears the origin after entering products on a new-transfer form
- **THEN** the system clears every product and serial row, clears row validation state, resets destination, and reloads product entry for the new origin

#### Scenario: Confirm creation-time condition change with entered rows
- **WHEN** the operator confirms a change between good-stock and breakage-stock mode after entering products on a new-transfer form
- **THEN** the system clears every product and serial row and reloads product entry for the selected mode while preserving valid location selections

#### Scenario: Cancel creation-time condition change
- **WHEN** the operator declines confirmation for a condition change after entering products
- **THEN** the system preserves the prior condition and every existing product and serial row

#### Scenario: Re-select the current origin or condition
- **WHEN** the creation form receives the same origin or condition value it already holds
- **THEN** the system preserves the existing product rows

#### Scenario: Change destination with entered rows
- **WHEN** the operator selects, changes, or clears only the destination
- **THEN** the system preserves all product and serial rows while revalidating destination compatibility separately

#### Scenario: Attempt an edit-time context change
- **WHEN** a client attempts to change the origin or stock condition of a persisted transfer
- **THEN** the system rejects the request without clearing or changing its persisted rows

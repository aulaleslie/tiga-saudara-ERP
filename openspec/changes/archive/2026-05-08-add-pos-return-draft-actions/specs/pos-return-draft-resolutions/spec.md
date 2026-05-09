## ADDED Requirements

### Requirement: Draft List Actions

The system SHALL show POS Return list actions for draft returns only. A POS Return in `draft` status and draft approval state MUST expose `Edit` to users with `pos.returns.edit`, `Delete` to users with `pos.returns.delete`, and `Ajukan Persetujuan` to users authorized to submit POS return drafts. The system MUST NOT show these draft actions for pending approval, approved, rejected, awaiting receiving, awaiting settlement, awaiting dispatch, manual correction required, archived, cancelled, completed, or deleted returns.

#### Scenario: Draft row shows permitted draft actions
- **WHEN** an authorized user views `/pos/returns`
- **AND** a POS Return row has `status` of `draft` and draft approval state
- **THEN** the row shows the draft actions allowed by the user's POS return permissions
- **AND** the row includes `Ajukan Persetujuan` when the user is allowed to submit draft POS returns

#### Scenario: Non-draft row hides draft actions
- **WHEN** an authorized user views `/pos/returns`
- **AND** a POS Return row is not in `draft` status
- **THEN** the row does not show `Edit`, `Delete`, or `Ajukan Persetujuan` draft actions

#### Scenario: Crafted draft action is rejected for non-draft return
- **WHEN** a user submits a draft edit, delete, or submit-to-approval request for a POS Return that is not in `draft` status
- **THEN** the system blocks the action with a clear invalid lifecycle action response

### Requirement: Draft Submit Moves Return To Pending Approval

The system SHALL provide an `Ajukan Persetujuan` action that moves a valid draft POS Return from `status = draft` and `approval_status = draft` to `status = pending_approval` and `approval_status = pending`. This action MUST validate the persisted draft before changing status, MUST record the submitting actor and timestamp when supported by existing audit fields, and MUST NOT create Sales Return records, Sale Return Details, stock mutations, dispatch quantity reductions, payment settlements, replacement dispatches, serial-status mutations, or inventory transaction history.

#### Scenario: Submit valid draft for approval
- **WHEN** an authorized user clicks `Ajukan Persetujuan` for a valid draft POS Return
- **THEN** the system changes the POS Return status to `pending_approval`
- **AND** changes the approval status to `pending`
- **AND** no execution-side records or mutations are created

#### Scenario: Submit empty or invalid draft is blocked
- **WHEN** an authorized user clicks `Ajukan Persetujuan` for a draft POS Return with no actionable return lines or invalid persisted line data
- **THEN** the system keeps the POS Return in draft status
- **AND** shows a clear validation message
- **AND** no execution-side records or mutations are created

#### Scenario: Submit stale draft is blocked
- **WHEN** an authorized user clicks `Ajukan Persetujuan` for a draft POS Return whose source snapshot or returnable source data is stale
- **THEN** the system keeps the POS Return in draft status
- **AND** shows a clear message that the draft must be refreshed or edited before submission

### Requirement: Create And Edit Share Return Form Surface

The system SHALL render POS Return create and edit line selection through a shared form surface so both screens use the same grouping, resolution controls, quantity behavior, replacement serial input behavior, bundle trace display, component availability display, cash total summary, validation message placement, and loading states. Create MAY include the transaction lookup step before the shared surface is shown. Edit MUST preload the existing draft selections and omit transaction lookup.

#### Scenario: Edit line controls match create line controls
- **WHEN** an authorized user opens the edit page for a draft POS Return
- **THEN** the return-line groups, serial resolution controls, non-serial quantity controls, replacement serial controls, bundle trace display, totals, and validation placement match the create form behavior for the same source transaction

#### Scenario: Edit preloads saved draft selections
- **WHEN** an authorized user opens the edit page for a draft POS Return with saved line selections
- **THEN** the shared form surface preloads those selections
- **AND** changing and saving the form updates the same draft without execution-side mutations

## MODIFIED Requirements

### Requirement: Draft And Rejected Edit Rules

The system SHALL allow POS Returns in `draft` status and draft approval state to be edited. Editing MUST revalidate source snapshot freshness and replacement serial availability. This change does not add rejected return edit behavior; rejected returns MUST NOT use the draft edit action introduced by this change.

#### Scenario: Edit draft return
- **WHEN** an authorized user edits and saves a draft POS Return
- **THEN** the system updates the draft header and rebuilds draft lines from the submitted selections
- **AND** no execution-side mutation occurs

#### Scenario: Draft edit action rejects rejected return
- **WHEN** a user attempts to use the draft edit action for a rejected POS Return
- **THEN** the system blocks the draft edit action
- **AND** keeps the POS Return status and approval status unchanged

### Requirement: Draft And Rejected Delete Rules

The system SHALL allow draft POS Returns to be hard-deleted because they have no execution effects. This change does not add rejected return delete behavior; rejected returns MUST NOT use the draft hard-delete action introduced by this change. Delete behavior for approved POS Returns remains outside this change.

#### Scenario: Hard delete draft
- **WHEN** an authorized user deletes a draft POS Return
- **THEN** the system removes the draft header and draft lines
- **AND** no execution-side mutation occurs

#### Scenario: Draft delete action rejects rejected return
- **WHEN** a user attempts to use the draft delete action for a rejected POS Return
- **THEN** the system blocks the draft delete action
- **AND** keeps the POS Return status and approval status unchanged

#### Scenario: Approved delete remains out of scope
- **WHEN** an authorized user attempts draft delete behavior on an approved POS Return
- **THEN** the system blocks direct delete because approved archive behavior is outside this change

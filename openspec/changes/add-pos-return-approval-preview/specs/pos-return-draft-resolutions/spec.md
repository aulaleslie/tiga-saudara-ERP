## MODIFIED Requirements

### Requirement: Draft Submit Uses Authoring Permission And Locks Pending Approval
The system SHALL allow users with POS Return draft authoring permission to submit a valid draft POS Return for approval. Submit-for-approval MUST require either `pos.returns.create` or `pos.returns.edit`; approver preview and decision entry points MUST still require `pos.returns.approve`. After submission, the POS Return MUST enter `pending_approval` status and `pending` approval status and MUST NOT be editable or deletable. For this preview-only change, the pending POS Return approve action MUST open approval preview instead of approving immediately, and no direct web-accessible final approval mutation endpoint may approve the return.

#### Scenario: Draft author submits return for approval
- **WHEN** a user with `pos.returns.edit` or `pos.returns.create` submits a valid draft POS Return for approval
- **THEN** the system changes the POS Return to `pending_approval` status and `pending` approval status
- **AND** no Sales Return, stock, dispatch, payment, replacement dispatch, serial execution, or inventory transaction mutation occurs

#### Scenario: Pending approval return is locked from revision
- **WHEN** a POS Return is in `pending_approval` status and `pending` approval status
- **THEN** the system blocks edit and delete actions for the POS Return
- **AND** the list and detail pages do not show edit or delete actions for that POS Return

#### Scenario: Approver permission remains required for approval preview
- **WHEN** a user without `pos.returns.approve` attempts to open approval preview for a pending POS Return
- **THEN** the system denies the approval preview action
- **AND** keeps the POS Return status and approval status unchanged

#### Scenario: Approve action does not approve immediately
- **WHEN** a user with `pos.returns.approve` clicks the approve action for a POS Return in `pending_approval` status and `pending` approval status
- **THEN** the system opens the approval preview page
- **AND** the POS Return remains in `pending_approval` status and `pending` approval status
- **AND** no Sales Return, stock, dispatch, payment, replacement dispatch, serial execution, inventory transaction, or approval audit mutation occurs

#### Scenario: Direct approval mutation is blocked during preview-only phase
- **WHEN** a user submits the direct approval mutation endpoint for a POS Return in `pending_approval` status and `pending` approval status
- **THEN** the system blocks the request with a clear preview-only lifecycle message
- **AND** the POS Return remains in `pending_approval` status and `pending` approval status
- **AND** no Sales Return, stock, dispatch, payment, replacement dispatch, serial execution, inventory transaction, or approval audit mutation occurs

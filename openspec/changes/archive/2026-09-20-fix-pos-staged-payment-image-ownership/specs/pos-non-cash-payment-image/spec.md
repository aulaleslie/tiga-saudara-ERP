# Spec Delta

## MODIFIED Requirements

### Requirement: Committed stage images survive later-stage transitions
After the system commits a non-cash payment stage with an image, that image token SHALL be owned by the committed payment chain until finalization consumes it, the complete payment chain is reset, or expiry cleanup removes an unconsumed upload. Resetting the active stage form, selecting a later payment method, or enabling Utang/Kas Bon MUST NOT delete it. Individual image removal MUST reject a token referenced by a committed payment stage, while an image that remains owned only by the active form SHALL remain removable. Asynchronous cleanup of an active-form image MUST NOT clear or delete a newer active-form upload.

#### Scenario: Cash follows an attached transfer stage
- **WHEN** a cashier commits a transfer stage with image evidence and then selects Cash for the remaining balance
- **THEN** the transfer image remains available for checkout finalization
- **AND** the Cash stage has no image token

#### Scenario: Finalization attaches only the transfer image
- **WHEN** the transfer-with-image stage followed by Cash finalizes successfully
- **THEN** the Transfer Sale Payment receives the image in its `attachments` collection
- **AND** the Cash Sale Payment receives no attachment

#### Scenario: Active-form reset after successful stage
- **WHEN** a non-cash stage with an image is successfully committed and the UI prepares the next payment stage
- **THEN** the active form clears its local attachment selection
- **AND** the committed stage's temporary image is not deleted

#### Scenario: Kas Bon follows an attached partial transfer
- **WHEN** a cashier commits a partial transfer stage with image evidence and enables Utang/Kas Bon for the remaining balance
- **THEN** checkout finalization succeeds subject to existing debt authorization and payment-term rules
- **AND** the transfer Sale Payment receives the committed image
- **AND** the outstanding debt does not receive or inherit that image

#### Scenario: Individual removal targets a committed image
- **WHEN** an individual image-removal request targets a token referenced by a committed payment stage in the same cart payment chain
- **THEN** the system rejects the request as a conflict
- **AND** the temporary image remains available for finalization or complete-chain reset

#### Scenario: Individual removal targets a pending image
- **WHEN** a cashier explicitly removes an image before its payment stage is committed
- **THEN** the system deletes that scoped temporary image
- **AND** the active form no longer supplies its token

#### Scenario: Pending image deletion completes after a replacement upload
- **WHEN** deletion of an earlier active-form image completes after the cashier has uploaded a replacement image
- **THEN** the replacement remains selected and available for its payment stage
- **AND** cleanup of the earlier image does not mutate the replacement state

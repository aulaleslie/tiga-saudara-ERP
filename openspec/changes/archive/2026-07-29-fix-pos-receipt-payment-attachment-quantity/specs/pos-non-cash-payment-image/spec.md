## ADDED Requirements

### Requirement: Committed stage images survive later-stage transitions
After the system commits a non-cash payment stage with an image, that image token SHALL be owned by the committed payment chain until finalization consumes it, the complete payment chain is reset, or expiry cleanup removes an unconsumed upload. Resetting the active stage form or selecting a later payment method MUST NOT delete it.

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

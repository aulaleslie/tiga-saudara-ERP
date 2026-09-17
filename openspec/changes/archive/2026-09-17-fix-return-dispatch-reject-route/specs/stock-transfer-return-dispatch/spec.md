## ADDED Requirements

### Requirement: Pending return-dispatch review exposes a working rejection action
The system SHALL render an authorized pending version 2 return-dispatch review page with a rejection form that targets a registered POST route. A reasoned submission through that route SHALL invoke the existing authorized rejection lifecycle and persist the rejected movement with its reason and audit history.

#### Scenario: Authorized approver reviews a pending batch
- **WHEN** an authorized destination-side approver opens the review page for a pending return-dispatch movement
- **THEN** the page renders with a rejection form that targets the movement's registered rejection route

#### Scenario: Approver rejects a pending batch
- **WHEN** the authorized approver submits a nonempty rejection reason from that form
- **THEN** the movement becomes rejected, and the reason and rejection history are persisted

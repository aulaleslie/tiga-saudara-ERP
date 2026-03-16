## MODIFIED Requirements

### Requirement: Approval Request State MUST Be Deterministic And Queryable
The system SHALL expose deterministic approval states so users can explicitly check whether a submitted request is still pending, approved, or rejected. The approval request creation endpoint MUST return a complete `cart_snapshot` contract matching other cart mutation endpoints, ensuring immediate cart re-render and approval state visibility.

#### Scenario: Pending request remains actionable for re-check
- **WHEN** a user checks approval status for a request that is still pending
- **THEN** the response MUST return `pending`, MUST NOT return execution token, and MUST allow user to check again later

#### Scenario: Approved request issues execution token
- **WHEN** a supervisor approves a pending request and the requester checks status
- **THEN** the response MUST return `approved` and MUST include a one-time execution token for the requested action

#### Scenario: Rejected request closes without mutation
- **WHEN** a supervisor rejects a pending request and the requester checks status
- **THEN** the response MUST return `rejected` and MUST keep cart state unchanged

#### Scenario: Approval request creation returns deterministic cart snapshot
- **WHEN** a non-authorized user submits a cart action approval request and the request is successfully created
- **THEN** the POST response MUST include `cart_snapshot` matching the contract of other cart mutation endpoints, so the frontend can immediately render the updated cart UI with approval controls visible

#### Scenario: Approval controls render immediately after submission
- **WHEN** the frontend receives the `cart_snapshot` from the approval request creation response
- **THEN** the cart UI MUST re-render all lines, evaluate approval state metadata, and display pending approval controls without requiring an additional cart refresh

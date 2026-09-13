## MODIFIED Requirements

### Requirement: Transfers support a staged versioned movement foundation
The system SHALL store a workflow version on every stock transfer, SHALL assign workflow version `2` only to new same-business and cross-business non-PKP-to-non-PKP transfers once forward dispatch and forward receipt are available, and SHALL retain workflow version `1` for PKP-involved routes until Delivery 7 completes their policy lifecycle.

#### Scenario: Eligible new transfer uses version 2
- **WHEN** a new transfer is same-business or both participating businesses are non-PKP
- **THEN** it is eligible for workflow version `2` forward dispatch and forward receipt

#### Scenario: PKP-involved transfer remains version 1
- **WHEN** either participating business is PKP before Delivery 7 activation
- **THEN** the transfer remains workflow version `1` and no version `2` movement surface is available

#### Scenario: No historical transfer migration
- **WHEN** Delivery 6 is deployed
- **THEN** the system performs no legacy transfer-transaction backfill or reinterpretation because stock transfer has no operational historical population

### Requirement: Transit custody closes only for approved exact forward receipt
The system SHALL activate serialized transit custody only on approved workflow version `2` forward dispatch and SHALL close that custody, move live serial location to destination, and remove the active claim only within successful approval of the exact linked forward receipt.

#### Scenario: Approve serialized forward dispatch
- **WHEN** an eligible serialized forward dispatch completes approval atomically
- **THEN** its movement serials become exclusively in transit while live serial locations remain at their last confirmed origin

#### Scenario: Approve exact linked receipt
- **WHEN** the linked receipt exactly matches and completes approval atomically
- **THEN** live serial locations move to destination, movement custody closes, and active transfer claims are removed

#### Scenario: Receipt does not approve
- **WHEN** receipt preparation, submission, comparison, rejection, correction, cancellation, or approval fails
- **THEN** dispatched serials remain in transit and unavailable for competing operations

#### Scenario: Legacy serial lifecycle
- **WHEN** a workflow version `1` transfer dispatches or receives
- **THEN** its established serial behavior remains unchanged by movement custody

### Requirement: Movement foundation exposes gated forward dispatch and receipt
The system SHALL expose movement creation, mutation, submission, review, rejection, correction, and approval for forward dispatch and forward receipt through version-aware routes guarded by route eligibility, action permissions, operational ownership, scoped aggregate identity, and stock-visibility projections; return movement types MUST remain without production-facing surfaces in this change.

#### Scenario: Authorized origin dispatch action
- **WHEN** an eligible origin user invokes a workflow version `2` forward-dispatch action
- **THEN** the dispatch movement surface is available using the user's permitted projection

#### Scenario: Authorized destination receipt action
- **WHEN** an eligible destination user invokes a workflow version `2` forward-receipt action
- **THEN** preparation uses the universally blind projection and approval uses the approver's visibility-aware projection

#### Scenario: Return movement route attempted
- **WHEN** any user attempts to access a return-dispatch or return-receipt movement surface in this change
- **THEN** no such surface is available and no movement or inventory effect occurs

#### Scenario: Ineligible route attempts version 2
- **WHEN** a PKP-involved transfer invokes a version `2` movement action before Delivery 7
- **THEN** the action is rejected and version `1` remains authoritative

## ADDED Requirements

### Requirement: Empty receipt confirmation is explicit and auditable
A forward-receipt movement SHALL store document-level physical-count confirmation independently from movement lines, including confirmer and timestamp, so an intentional empty observation can be submitted without exposing or synthesizing expected lines.

#### Scenario: Explicitly confirm empty receipt
- **WHEN** an authorized destination preparer confirms that no goods were physically received
- **THEN** the draft records the confirmation actor and timestamp and may be submitted empty

#### Scenario: Empty draft is not confirmed
- **WHEN** a draft contains no positive observations and lacks current document-level confirmation
- **THEN** it is incomplete and cannot be submitted

#### Scenario: Observations change after confirmation
- **WHEN** receipt observations are added, removed, or changed after empty confirmation
- **THEN** the obsolete document-level confirmation is cleared

### Requirement: Receipt corrections restart blind
A correction created for a rejected forward receipt SHALL reference and preserve the rejected revision but SHALL start with no copied lines, serials, quantities, or hidden expectations.

#### Scenario: Correct rejected receipt
- **WHEN** an authorized destination preparer starts a correction for a rejected receipt
- **THEN** the next revision is an empty blind draft linked through `supersedes_movement_id`

#### Scenario: Inspect correction payload
- **WHEN** the correction is rendered for preparation
- **THEN** neither rejected observations nor source dispatch expectations appear unless newly entered by the operator

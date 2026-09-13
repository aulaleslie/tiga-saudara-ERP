## MODIFIED Requirements

### Requirement: Movement attempts prevent competing operational documents
The system MUST serialize revision creation within each movement lineage, MUST permit at most one open `DRAFT` or `PENDING` attempt per lineage, and MUST permit at most one approved attempt in a forward movement or receipt lineage. It SHALL permit multiple independently approved `RETURN_DISPATCH` lineages for one transfer so concurrent partial batches can exist, while assigning unique stable identities and increasing correction revisions within each lineage.

#### Scenario: Competing open attempt in one lineage
- **WHEN** a draft or pending correction already exists for a movement lineage and another actor attempts to create a competing revision
- **THEN** creation is rejected and only the original open attempt remains

#### Scenario: Duplicate approved attempt in one lineage
- **WHEN** an approved attempt already exists in a lineage
- **THEN** another revision in that lineage cannot be approved

#### Scenario: Separate partial return batches
- **WHEN** one return-dispatch batch is already approved and capacity remains outstanding
- **THEN** a new independent return-dispatch lineage may be created and approved

#### Scenario: Concurrent revision creation
- **WHEN** concurrent requests try to create the next correction revision in the same lineage
- **THEN** parent locking and unique revision identity allow at most one request to persist that revision

### Requirement: Movement foundation exposes gated forward dispatch and receipt
The system SHALL expose movement creation, mutation, submission, review, rejection, correction, and approval for forward dispatch, forward receipt, and eligible return dispatch through version-aware routes guarded by route eligibility, action permissions, operational ownership, scoped aggregate identity, route policy, source lineage, and stock-visibility projections; return receipt MUST remain without a production-facing surface in this change.

#### Scenario: Authorized origin forward-dispatch action
- **WHEN** an eligible origin user invokes a workflow version `2` forward-dispatch action
- **THEN** the dispatch movement surface is available using the user's permitted projection

#### Scenario: Authorized destination forward-receipt action
- **WHEN** an eligible destination user invokes a workflow version `2` forward-receipt action
- **THEN** preparation uses the universally blind projection and approval uses the approver's visibility-aware projection

#### Scenario: Authorized destination return-dispatch action
- **WHEN** a destination-side user with the applicable dispatch action permission accesses an eligible mandatory-return workflow version `2` transfer
- **THEN** the return-dispatch surface is available using the user's visibility-aware projection

#### Scenario: Return-receipt route attempted
- **WHEN** any user attempts to access a return-receipt movement surface in this change
- **THEN** no such surface is available and no movement, inventory, obligation, or custody effect occurs

#### Scenario: Legacy workflow invokes movement route
- **WHEN** a workflow version `1` transfer invokes a version `2` movement action
- **THEN** the action is rejected and established legacy behavior remains authoritative

## ADDED Requirements

### Requirement: Return-dispatch movements retain exact source and batch lineage
Every return-dispatch lineage SHALL reference the exact approved forward receipt that created its obligations, the committed transfer policy/revision, and a stable batch identity shared by its rejected and correction revisions.

#### Scenario: Create a new partial batch
- **WHEN** an eligible transfer has unreserved outstanding obligation capacity
- **THEN** the system creates a new batch lineage whose first return-dispatch revision references the approved forward receipt

#### Scenario: Source receipt belongs to another transfer
- **WHEN** a crafted request supplies an approved receipt from another transfer or revision
- **THEN** creation or mutation is rejected without persisting a movement

#### Scenario: Correct a rejected batch
- **WHEN** a rejected return-dispatch attempt is corrected
- **THEN** the new revision retains the same batch and source lineage while superseding the rejected attempt

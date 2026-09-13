## ADDED Requirements

### Requirement: Authorized origin users prepare a forward-dispatch physical count
For a workflow version `2` approved transfer, the system SHALL allow an actor with `stockTransfers.dispatch.create` operating under the origin business to create or edit the single open forward-dispatch draft using approved product identities and the transfer's stock condition.

#### Scenario: Origin dispatcher opens preparation
- **WHEN** an origin-side user with dispatch-create permission opens an eligible approved transfer
- **THEN** the system creates or resumes its forward-dispatch draft bound to the exact approved transfer revision

#### Scenario: Unauthorized or wrong-side preparation
- **WHEN** a user lacks dispatch-create permission or the active business does not own the origin location
- **THEN** the system rejects access and mutation without exposing or changing movement or inventory data

#### Scenario: Legacy transfer uses legacy dispatch
- **WHEN** a workflow version `1` transfer is dispatched
- **THEN** the existing legacy route, permission, lifecycle, inventory, and serial behavior remains authoritative

### Requirement: Dispatch preparation reuses authoritative scanner behavior
The system SHALL support primary barcode, whole base-unit conversion barcode, serial-number scan, and tokenized product search behavior consistent with transfer document creation, and MUST authoritatively revalidate product, conversion, origin, condition, serial identity, availability, and duplicate state at every mutation boundary.

#### Scenario: Repeated product and conversion scans
- **WHEN** a dispatcher repeatedly scans a non-serialized product barcode or supported conversion barcode
- **THEN** the movement accumulates the correct canonical base-unit physical count deterministically

#### Scenario: Scan an eligible serial
- **WHEN** a dispatcher scans an available serial at the origin matching the movement product and condition
- **THEN** the serial is selected once and serialized quantity derives from the unique selected serial count

#### Scenario: Scan an alternate eligible serial
- **WHEN** the dispatcher scans an eligible serial for an approved product that differs from the requested serial
- **THEN** the physical observation is retained without disclosing during blind preparation whether the serial matches the request

#### Scenario: Scan an unexpected product
- **WHEN** the dispatcher physically finds and scans a valid origin product absent from the approved request
- **THEN** the unexpected product and count are retained for submitted comparison rather than silently discarded

### Requirement: Every approved product requires explicit count confirmation
The system SHALL distinguish an unfinished product count from a deliberate physical count of zero by storing confirmation independently from quantity, and SHALL require every approved product to be confirmed before submission.

#### Scenario: Confirm a positive count
- **WHEN** an approved product is scanned or its valid physical quantity is deliberately entered
- **THEN** its movement line stores the operator-entered quantity and confirmed state

#### Scenario: Confirm zero stock physically found
- **WHEN** the dispatcher explicitly confirms that zero units of an approved product were physically found
- **THEN** the movement retains a confirmed line with zero quantity

#### Scenario: Submit with an unconfirmed approved product
- **WHEN** any approved product remains unconfirmed
- **THEN** submission is rejected without revealing its requested quantity

#### Scenario: Submit an all-zero physical count
- **WHEN** every approved product is explicitly confirmed with zero quantity
- **THEN** the physical count may be submitted for discrepancy review

### Requirement: Submitted dispatch is compared canonically with the approved request
The system SHALL compare the submitted dispatch with the exact approved transfer revision by product, base quantity, stock condition, and, for serialized products, normalized serial identity set; ordering and scan sequence MUST NOT affect equality.

#### Scenario: Exact non-serialized count
- **WHEN** submitted product identities, condition, and base quantities exactly equal the approved request
- **THEN** request/count comparison passes

#### Scenario: Exact serialized count
- **WHEN** submitted serialized product identities, condition, quantities, and serial sets exactly equal the approved request
- **THEN** request/count comparison passes regardless of scan order

#### Scenario: Missing, zero, shortage, or excess observation
- **WHEN** a submitted product count differs from its approved requested quantity
- **THEN** comparison fails and identifies the discrepancy only in a privileged projection

#### Scenario: Unexpected product or alternate serial
- **WHEN** the submitted observation contains an unrequested product or a different serial set
- **THEN** comparison fails while preserving the physical observation for audit and correction

### Requirement: Only exact and fulfillable dispatches can be approved
The system MUST approve a pending forward-dispatch movement only when request/count comparison passes and locked authoritative origin stock and serials can fulfill the complete manifest in the selected condition.

#### Scenario: Approve exact fulfillable dispatch
- **WHEN** comparison passes and all locked origin stock and serial validations pass
- **THEN** the system approves and applies the dispatch atomically

#### Scenario: Attempt approval with mismatch
- **WHEN** any product, quantity, condition, or serial comparison differs
- **THEN** approval is blocked, the movement remains `PENDING`, and no inventory or custody effect occurs

#### Scenario: Stock changes after submission
- **WHEN** authoritative origin stock or serial availability cannot fulfill an otherwise matching submitted manifest at approval time
- **THEN** approval is blocked atomically and the pending physical observation remains unchanged

#### Scenario: Explicit rejection after failed review
- **WHEN** an approver rejects a pending mismatched or unfulfillable dispatch with a reason
- **THEN** the rejected revision remains immutable and an authorized preparer may create a superseding correction draft

### Requirement: Forward-dispatch approval atomically deducts origin inventory
Approved workflow version `2` forward dispatch SHALL deduct exactly the submitted and authoritatively allocated manifest from the applicable origin good or broken buckets, record immutable before/after allocation and inventory references, approve the movement, and project the transfer header to `DISPATCHED` in one database transaction.

#### Scenario: Approve good-stock dispatch
- **WHEN** a matching `GOOD` dispatch is approved
- **THEN** only origin good non-tax/tax buckets are allocated and deducted and broken buckets remain unchanged

#### Scenario: Approve broken-stock dispatch
- **WHEN** a matching `BREAKAGE` dispatch is approved
- **THEN** only origin broken non-tax/tax buckets are allocated and deducted and good buckets remain unchanged

#### Scenario: Later line fails
- **WHEN** any stock, serial, transaction, custody, history, or header update fails after processing begins
- **THEN** all forward-dispatch approval effects roll back

#### Scenario: Repeat approved request
- **WHEN** the same approval is replayed with the same idempotency identity
- **THEN** inventory, transactions, custody, history, and header projection are applied at most once

### Requirement: Serialized dispatch creates exclusive in-transit custody
On approved version `2` forward dispatch, the system SHALL create or activate one exclusive unresolved transit-custody claim for every approved serial, SHALL leave its live location at the origin as the last confirmed location, and SHALL exclude it from every operational availability path until receipt closes custody.

#### Scenario: Approve serialized forward dispatch
- **WHEN** a matching serialized dispatch is approved
- **THEN** each serial is marked in transit toward the destination through movement custody while its live location remains the origin

#### Scenario: Attempt competing use of in-transit serial
- **WHEN** sale, dispatch, return, or transfer selection attempts to use a serial with active transfer custody
- **THEN** the serial is unavailable and the competing mutation is rejected

#### Scenario: Concurrent custody claims
- **WHEN** concurrent approvals attempt to claim the same serial
- **THEN** locking and database-backed uniqueness permit at most one active custody claim

### Requirement: Forward-dispatch activation waits for forward receipt
The system SHALL keep production assignment and activation of workflow version `2` disabled in this change while permitting focused non-production verification of the complete version `2` dispatch path.

#### Scenario: Deploy Delivery 5 configuration
- **WHEN** this change is deployed with its default configuration
- **THEN** newly created and approved production transfers remain workflow version `1` and cannot be stranded in the version `2` forward-dispatch lifecycle

#### Scenario: Crafted request attempts disabled version 2 entry
- **WHEN** a user directly invokes a version `2` forward-dispatch production route while activation is disabled
- **THEN** the system rejects the request without creating a movement or changing inventory


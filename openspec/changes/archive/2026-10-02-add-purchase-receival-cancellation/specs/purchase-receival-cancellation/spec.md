# Spec Delta

## Purpose

Provide an authorized, auditable, and inventory-safe way to cancel all effective receivals of a Purchase in one action while retaining their historical evidence and allowing safely reopened purchases to be edited and received again.

## ADDED Requirements

### Requirement: Authorized users cancel all receivals of a Purchase in one action
The system SHALL provide one "Batalkan Penerimaan" action on the Purchase detail page, available only to a user with `purchases.receive.cancel` for an unarchived ordinary Purchase in the active setting that has at least one `APPROVED` or `PENDING` receival. The action SHALL cancel every `APPROVED` and `PENDING` receival of that Purchase and the complete canonical quantity of every detail; selecting individual receivals, lines, or quantities SHALL NOT be supported. The user SHALL review the aggregate effect across all affected receivals and supply a non-empty reason before confirmation.

#### Scenario: Authorized user cancels a Purchase's receivals
- **WHEN** an authorized user confirms the purchase-level cancellation with a reason
- **THEN** every `APPROVED` and `PENDING` receival of the Purchase becomes `CANCELLED` atomically
- **AND** no detail or received quantity from any of them remains effective
- **AND** `REJECTED` and previously `CANCELLED` receivals are left unchanged

#### Scenario: One receival cannot be safely reversed
- **WHEN** any affected receival has a blocker such as insufficient exact-bucket stock, an unavailable serial, or ambiguous provenance
- **THEN** the system cancels none of the receivals and reports the blockers

#### Scenario: Receivals jointly exceed available stock
- **WHEN** several approved receivals each fit the available stock of a product, location, and tax bucket alone but their combined quantity does not
- **THEN** the system rejects the whole action

#### Scenario: Client attempts to target a single receival
- **WHEN** a client supplies receival IDs, selected details, or reduced quantities
- **THEN** no endpoint accepts them and the system performs no partial cancellation

#### Scenario: Purchase has nothing to cancel
- **WHEN** the Purchase has no `APPROVED` or `PENDING` receival
- **THEN** the action is not offered and a submission is rejected without effect

#### Scenario: Unauthorized user attempts cancellation
- **WHEN** a user without `purchases.receive.cancel` requests a preview or submits cancellation
- **THEN** the system denies the request and changes no receival, Purchase, inventory, serial, cost, notification, or audit data

#### Scenario: Missing reason blocks cancellation
- **WHEN** an authorized user submits cancellation without a non-empty reason
- **THEN** validation fails and the system persists no cancellation effect

### Requirement: Approved receival cancellation creates an exact compensating inventory movement
The system SHALL retain every original `BUY` transaction and create auditable compensating inventory transactions for the full positive quantity of every detail in every cancelled approved receival. Each compensation SHALL use the original product, setting, receiving location, canonical quantity, and tax or non-tax bucket, and SHALL retain durable links to the cancellation, source detail, and original `BUY` transaction.

#### Scenario: Taxable and non-taxable lines are cancelled
- **WHEN** an eligible receival contains positive taxable and non-taxable details
- **THEN** the system deducts each quantity from its exact original location and stock bucket
- **AND** records separate compensating transactions with before-and-after quantities
- **AND** retains the original `BUY` transactions unchanged

#### Scenario: Original location lacks sufficient stock
- **WHEN** any exact original location and stock bucket has less available quantity than the complete reversal requires
- **THEN** the system rejects the entire cancellation
- **AND** no stock bucket becomes negative or borrows quantity from another location or bucket

#### Scenario: Legacy transaction provenance is ambiguous
- **WHEN** an approved legacy receival detail has no durable `BUY` link and conservative evidence resolves zero or multiple candidate transactions
- **THEN** the system rejects the entire cancellation rather than guessing the source movement

### Requirement: Serial-tracked receivals are cancelled only while every serial remains safely reversible
The system SHALL lock and validate every serial attributed to an affected approved receival. Cancellation SHALL require each serial to retain authoritative active provenance from that receival, remain `ACTIVE`, remain available at the original receiving location and tax identity, and have no active transfer or return claim. A `SOLD`, `RETURN_IN_PROCESS`, `RETURNED`, `BROKEN`, `MISSING`, relocated, reused, or otherwise unavailable serial SHALL block the entire cancellation.

#### Scenario: A receival serial has been sold
- **WHEN** any serial attributed to an affected approved receival has status `SOLD`
- **THEN** the system rejects the entire cancellation
- **AND** reports the blocking serial without changing any data

#### Scenario: A receival serial is not safely available
- **WHEN** any attributed serial is relocated, reused by later receiving provenance, in transfer or return processing, returned, broken, missing, or otherwise not active at the original location
- **THEN** the system rejects the entire cancellation

#### Scenario: All serials are safely reversible
- **WHEN** every attributed serial is active, available, and authoritatively belongs to its approved receival
- **THEN** cancellation moves every serial to `RECEIVING_CANCELLED`
- **AND** appends cancellation history without deleting its original received history or links

### Requirement: Cancellation respects downstream material dependencies
The system SHALL reject approved receival cancellation when its inventory evidence has been consumed or transformed by a Purchase return, supplier-shortfall completion, incompatible UOM normalization, or another dependency that prevents an exact and auditable reversal. Compatible normalized quantities MAY be reversed only when their current canonical values and source provenance are unambiguous.

#### Scenario: Goods participate in a Purchase return
- **WHEN** any quantity or serial from an affected receival participates in an active or completed Purchase return
- **THEN** the system rejects cancellation without changing either document

#### Scenario: Purchase was completed for supplier shortfall
- **WHEN** the Purchase has a completed supplier-shortfall normalization
- **THEN** the system rejects receival cancellation because the original ordered-line structure has been finalized

### Requirement: Cancellation returns the Purchase to APPROVED
After the purchase-level cancellation, no effective receival remains, so the system SHALL set the Purchase to `APPROVED` and record a status transition audit when the status changed. Cancelled pending receivals SHALL retain their history, record their previous status, the actor, time, reason, and origin, and have their approval notifications resolved.

#### Scenario: Purchase with approved and pending receivals is cancelled
- **WHEN** a `RECEIVED PARTIALLY` or `RECEIVED` Purchase with approved and pending receivals is cancelled
- **THEN** the Purchase becomes `APPROVED`
- **AND** every approved and pending receival becomes `CANCELLED` in the same transaction

#### Scenario: Cancelled pending receival is displayed
- **WHEN** a user views a pending receival cancelled by the purchase-level action
- **THEN** history identifies its previous status, cancellation actor, time, and reason
- **AND** the receival can never subsequently be approved or reactivated

### Requirement: Cancelled receival history survives Purchase editing
The system SHALL retain cancelled receival headers, details, original quantities, product identity, UOM and conversion values, tax identity, location, notes, serial references, approval evidence, cancellation evidence, and inventory references even if a later full Purchase edit replaces or removes live Purchase detail rows. Cancelled receivals SHALL remain visible with an unmistakable `CANCELLED` status and SHALL never regain an effective lifecycle state.

#### Scenario: Reopened Purchase is edited
- **WHEN** an authorized user fully edits an `APPROVED` Purchase after its approved and pending receivals were cancelled
- **THEN** the edit may replace current Purchase lines
- **AND** all cancelled receival documents and their historical line displays remain intact

#### Scenario: Purchase is received again after editing
- **WHEN** the edited Purchase is subsequently received
- **THEN** a new receival references the new current Purchase detail rows
- **AND** no cancelled receival is reused or reactivated

### Requirement: Cancellation preserves commercial amounts and reconciles cost history
Cancelling a receival SHALL NOT change Purchase line amounts, header totals, supplier obligations, or Purchase payments. The system SHALL recompute affected last and average purchase cost history from remaining effective inventory evidence so that the cancelled receipt no longer contributes to current cost state or downstream replayed cost snapshots.

#### Scenario: Approved receival is cancelled
- **WHEN** cancellation completes successfully
- **THEN** Purchase commercial amounts and payments remain unchanged
- **AND** affected product cost state reflects the remaining effective receipt history

#### Scenario: Cost reconciliation fails
- **WHEN** required cost reconciliation cannot complete
- **THEN** the entire cancellation rolls back with no partial stock, serial, status, audit, notification, or cost effect

### Requirement: Cancellation is atomic, idempotent, and auditable
The system SHALL serialize cancellation against receiving approval, Purchase editing, lifecycle changes, shortfall completion, returns, and competing cancellation. Stock, transactions, serials, serial histories, statuses, pending-receival cancellation, notifications, cost reconciliation, and immutable audit evidence SHALL commit together or roll back together. A receival SHALL be cancelled at most once.

#### Scenario: Cancellation request is replayed
- **WHEN** the purchase-level cancellation is submitted more than once for the same Purchase
- **THEN** only the request that observes effective receivals under lock applies reversal effects
- **AND** later requests find nothing to cancel and are rejected without duplicating effects

#### Scenario: Cancellation races with approval or edit
- **WHEN** cancellation competes with receiving approval or a full Purchase edit
- **THEN** the operations serialize on authoritative Purchase and receival data
- **AND** at most one operation commits against the previously eligible state

#### Scenario: A required write fails
- **WHEN** any required cancellation write or cost reconciliation fails
- **THEN** the entire operation rolls back and every affected receival keeps its previous status

### Requirement: Cancelled receivals remain visible but ineffective
Receiving history and inventory mutation reporting SHALL show both original and compensating evidence with their actual event dates. Effective receiving totals, remaining-quantity calculations, completion eligibility, and operational reports SHALL exclude `CANCELLED` receivals.

#### Scenario: User views cancellation history
- **WHEN** a user opens the Penerimaan Barang history on the Purchase detail page after cancellation
- **THEN** every cancelled receival remains visible with a `Dibatalkan` badge and its approval and cancellation evidence
- **AND** inventory history shows the original inflow and dated cancellation outflow

#### Scenario: System calculates received quantity
- **WHEN** received totals are calculated after a receival is cancelled
- **THEN** quantities from the cancelled receival do not contribute


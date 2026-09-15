# purchase-receiving-approval-lifecycle

## Requirements

### Requirement: Receiving approval requires authoritative eligible state
The system SHALL approve a Purchase receiving note only when locked authoritative data shows that the note is `PENDING`, belongs to an unarchived ordinary Purchase in `APPROVED` or `RECEIVED PARTIALLY` status, targets an eligible active standard location owned by the same setting, and retains valid Purchase-detail relationships for every receiving detail.

#### Scenario: Eligible pending receiving is approved
- **WHEN** an authorized user approves a valid pending receiving for an eligible Purchase
- **THEN** the system approves it using locked authoritative Purchase, receiving, line, location, stock, and serial data

#### Scenario: Purchase state changed before approval
- **WHEN** a receiving approval request reaches the mutation boundary after its Purchase has left `APPROVED` or `RECEIVED PARTIALLY`
- **THEN** the system rejects the approval and preserves the receiving note, stock, transactions, serials, histories, notifications, audits, and Purchase header unchanged

#### Scenario: Receiving detail has lost its Purchase line
- **WHEN** a pending receiving detail no longer has a valid Purchase-detail relationship at approval time
- **THEN** the system fails the entire approval instead of skipping that detail

### Requirement: Approved receiving contains positive delivery evidence
A receiving note submitted for approval SHALL contain at least one detail with a strictly positive canonical `quantity_received`. The system SHALL permit zero-quantity rows alongside a positive row, but zero rows SHALL NOT count as delivery, increment stock, create inventory transactions, or independently advance the Purchase lifecycle.

#### Scenario: Receiving contains positive and zero rows
- **WHEN** a pending receiving has one positive row and one or more zero-quantity rows and otherwise passes approval validation
- **THEN** the receiving remains eligible for approval
- **AND** only positive quantities contribute to stock and Purchase receiving progress

#### Scenario: Receiving contains only zero rows
- **WHEN** every detail on a pending receiving has `quantity_received = 0` at the locked approval boundary
- **THEN** the system rejects approval atomically
- **AND** the Purchase remains unchanged

### Requirement: Approved quantities authoritatively derive Purchase status
After approving a receiving with positive delivery evidence, the system SHALL calculate cumulative delivery from `APPROVED` receiving-note details only and SHALL persist the Purchase as `RECEIVED` when every Purchase line is cumulatively fulfilled or `RECEIVED PARTIALLY` when any ordered quantity remains. A Purchase with positive approved receiving evidence SHALL NOT remain `APPROVED`.

#### Scenario: Approval fully fulfills every Purchase line
- **WHEN** approval makes every Purchase line's cumulative approved quantity equal to its ordered quantity
- **THEN** the receiving note becomes `APPROVED`
- **AND** the Purchase becomes `RECEIVED` in the same transaction

#### Scenario: Approval leaves an outstanding quantity
- **WHEN** approval contributes a positive quantity but at least one Purchase line remains under its ordered quantity
- **THEN** the receiving note becomes `APPROVED`
- **AND** the Purchase becomes `RECEIVED PARTIALLY` in the same transaction

#### Scenario: Pending and rejected quantities are present
- **WHEN** the system derives the Purchase status after approval
- **THEN** quantities from pending and rejected receiving notes do not contribute to fulfillment
- **AND** quantities from unapproved receiving notes are excluded from cumulative delivery

### Requirement: User-driven lifecycle transitions cannot reverse receiving evidence
The system SHALL allow user-driven Purchase transitions only from their defined source states: `DRAFTED` to `WAITING_APPROVAL`, `WAITING_APPROVAL` to `APPROVED` or `REJECTED`, and `REJECTED` to `DRAFTED`. Generic status and edit requests SHALL NOT set `RECEIVED PARTIALLY` or `RECEIVED`, and SHALL NOT move a Purchase with positive approved receiving evidence to or leave it in `APPROVED`.

#### Scenario: Stale approval form arrives after full receiving
- **WHEN** a request created while the Purchase was `WAITING_APPROVAL` attempts to set it to `APPROVED` after receiving approval has made it `RECEIVED`
- **THEN** the system rejects the stale transition
- **AND** the Purchase remains `RECEIVED`

#### Scenario: Stale approval form arrives after partial receiving
- **WHEN** a stale request attempts to set a `RECEIVED PARTIALLY` Purchase to `APPROVED`
- **THEN** the system rejects the transition
- **AND** the Purchase remains `RECEIVED PARTIALLY`

#### Scenario: Crafted generic request supplies a received status
- **WHEN** a client submits `RECEIVED` or `RECEIVED PARTIALLY` through a generic Purchase status or edit request
- **THEN** the system rejects or ignores the supplied derived status
- **AND** it derives no receiving outcome without approved receiving evidence

### Requirement: Competing Purchase mutations are serialized
Receiving approval, Purchase lifecycle transitions, full commercial edits, and shortfall completion SHALL lock and reload the authoritative Purchase before checking eligibility or changing lifecycle or line state. These operations SHALL use a consistent lock order for shared Purchase and receiving records so that at most one competing operation commits against a given state.

#### Scenario: Full edit races with receiving approval
- **WHEN** an already-open full Purchase edit is submitted concurrently with receiving approval
- **THEN** the operations serialize on the Purchase
- **AND** an edit that observes the received state fails without replacing Purchase lines

#### Scenario: Shortfall completion races with receiving approval
- **WHEN** shortfall completion and receiving approval execute concurrently for one Purchase
- **THEN** at most one operation commits against the previously eligible state
- **AND** the other operation reloads and rejects its now-stale assumptions

### Requirement: Receiving approval is atomic and idempotent
The system SHALL apply receiving approval at most once. Stock quantities and tax buckets, product totals, approval-coupled price updates, `BUY` transactions, serial records and links, serial history, receiving status, Purchase status, transactional notification state, and required audit evidence SHALL commit together or roll back together.

#### Scenario: Approval request is replayed
- **WHEN** the same receiving approval is submitted more than once
- **THEN** only the request that observes `PENDING` under lock may apply approval effects
- **AND** later requests report that the receiving was already processed without duplicating any effect

#### Scenario: Approval fails during a serial or stock mutation
- **WHEN** any required stock, transaction, serial, history, status, notification, or audit write fails
- **THEN** the entire approval rolls back
- **AND** the receiving remains `PENDING` with no partial inventory or Purchase-header effect

#### Scenario: External notification delivery is required
- **WHEN** approval schedules a notification whose delivery is not protected by the database transaction
- **THEN** delivery occurs only after the approval transaction commits

### Requirement: Purchase status transitions retain audit evidence
Every successful user-driven or receiving-derived Purchase status transition SHALL persist immutable evidence containing the Purchase and setting, optional source receiving note, previous status, resulting status, transition action/source, actor, and timestamp. Receiving-derived audit evidence SHALL be committed atomically with receiving approval.

#### Scenario: Receiving approval changes the Purchase header
- **WHEN** receiving approval changes a Purchase from `APPROVED` to `RECEIVED PARTIALLY` or `RECEIVED`
- **THEN** the system records the previous and resulting status, approving actor, source receiving note, and transition timestamp

#### Scenario: Approval audit cannot be persisted
- **WHEN** required transition audit persistence fails during receiving approval
- **THEN** the entire receiving approval rolls back

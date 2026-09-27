# stock-transfer-forward-receipt Specification

## Purpose
Authoritative destination forward receipt preparation, universally blind physical observations, document-level empty confirmation, canonical dispatch-vs-receipt comparison, atomic destination inventory additions from source provenance, transit custody closure, active claim removal, and idempotent approval for workflow version 2 transfers.

## Requirements

### Requirement: Destination users prepare an independent blind receipt
For an eligible workflow version `2` transfer with an approved forward dispatch, the system SHALL allow an actor with `stockTransfers.receive.create` operating under the destination business to create or resume the single open forward-receipt attempt sourced from that exact dispatch, and the draft SHALL start with no expected product lines.

#### Scenario: Open the first receipt draft
- **WHEN** an authorized destination user opens receipt preparation for an eligible `DISPATCHED` transfer
- **THEN** the system creates an empty `FORWARD_RECEIPT` draft linked to the exact approved `FORWARD_DISPATCH`

#### Scenario: Resume an open attempt
- **WHEN** the transfer already has a draft or pending forward-receipt attempt
- **THEN** the system resumes that attempt rather than creating a competing revision

#### Scenario: Wrong destination or source movement
- **WHEN** the active business does not own the destination or the source is absent, cross-transfer, non-dispatch, or non-approved
- **THEN** the system rejects access and mutation without exposing manifest or inventory data

### Requirement: Receipt preparation never exposes the dispatch manifest
The system MUST keep forward-receipt preparation completely blind for every preparer, including a preparer with stock visibility, by omitting expected products, quantities, serials, applied buckets, destination stock, claim provenance, comparison results, and differences from every browser payload.

#### Scenario: Blind preparer opens an empty receipt
- **WHEN** any authorized preparer opens a new receipt draft
- **THEN** the browser receives no expected lines or source-manifest values and displays only operator-entered observations

#### Scenario: Privileged preparer opens receipt entry
- **WHEN** the preparer also holds `stockTransfers.view-system-stock`
- **THEN** preparation remains equally blind and stock visibility does not reveal the dispatch manifest or destination stock

#### Scenario: Preparation payload is inspected
- **WHEN** rendered HTML, JSON, Livewire state, events, validation data, or session payloads are inspected
- **THEN** protected keys and distinctive expected values are absent rather than hidden, masked, hashed, or represented by placeholders

### Requirement: Receipt scanning records physical observations authoritatively
The system SHALL support destination-tenant product barcode, whole-unit conversion barcode, individual serial scan, and tokenized product search; SHALL retain unexpected products and substitute serials as physical observations; and MUST revalidate product scope, conversion, condition, serial identity, duplicate state, and relevant custody authority on the server.

#### Scenario: Scan a non-serialized product
- **WHEN** the recipient scans or selects a destination-catalogue non-serialized product, including one with zero recorded destination stock
- **THEN** its canonical base-unit physical quantity is added without revealing whether it was dispatched

#### Scenario: Scan an exact dispatched serial
- **WHEN** the recipient scans a serial under active custody from the source dispatch
- **THEN** the serial identity is observed once and line quantity derives from distinct observed serials

#### Scenario: Scan a substitute serial
- **WHEN** the recipient physically observes a different valid serial for the same or another destination-catalogue product
- **THEN** the observation is retained without claiming it matches the hidden manifest

#### Scenario: Scan a serialized product barcode
- **WHEN** a product or conversion barcode resolves to a serialized product
- **THEN** the system prompts for individual serial scanning and does not increment quantity manually

### Requirement: Empty receipt requires explicit document confirmation
The system SHALL distinguish an untouched empty receipt draft from a deliberate observation that nothing arrived by storing document-level physical-count confirmation with its actor and timestamp.

#### Scenario: Confirm nothing received
- **WHEN** the recipient explicitly confirms an empty physical receipt
- **THEN** the movement records document-level confirmation without creating hidden or expected product lines

#### Scenario: Submit untouched empty draft
- **WHEN** a receipt draft has no positive observations and no explicit empty confirmation
- **THEN** submission is rejected with neutral guidance

#### Scenario: Change an empty confirmed observation
- **WHEN** a user adds, removes, or changes a physical observation after confirming empty receipt
- **THEN** the stale empty confirmation is cleared and must be deliberately re-established if the draft becomes empty again

### Requirement: Any completed physical observation may be submitted
The system SHALL permit explicitly completed empty, partial, excess, shortage, unexpected-product, or substitute-serial receipt observations to become immutable `PENDING` attempts without adding inventory, moving serials, closing custody, or changing the transfer header.

#### Scenario: Submit partial receipt
- **WHEN** the recipient submits fewer products or quantities than the hidden dispatch manifest
- **THEN** the system freezes the observation as pending without inventory or custody effects

#### Scenario: Submit explicit empty receipt
- **WHEN** the recipient submits a document-level confirmed empty observation
- **THEN** the empty attempt becomes pending for discrepancy review

#### Scenario: Submit unexpected observations
- **WHEN** the receipt contains an unexpected product or substitute serial
- **THEN** the submitted evidence is retained immutably for review and correction

### Requirement: Receipt comparison uses the approved dispatch manifest
The system SHALL compare a submitted forward receipt against its exact approved forward dispatch by product, base quantity, stock condition, and normalized serial identity set, independent of line or scan order.

#### Scenario: Exact non-serialized receipt
- **WHEN** received product identities, condition, and base quantities exactly match the approved dispatch
- **THEN** comparison passes

#### Scenario: Exact serialized receipt
- **WHEN** received serialized quantities and distinct serial sets exactly match the approved dispatch
- **THEN** comparison passes regardless of scan order

#### Scenario: Receipt discrepancy
- **WHEN** any product, quantity, condition, or serial differs, is missing, or is unexpected
- **THEN** comparison fails while preserving the pending attempt and reveals exact differences only through a privileged approval projection

### Requirement: Only exact receipts can be approved
The system MUST approve a forward receipt only when the pending immutable observation exactly matches the approved source dispatch and all locked inventory, serial, custody, aggregate, and route-eligibility invariants pass.

#### Scenario: Approve exact receipt
- **WHEN** comparison and every authoritative locked validation pass
- **THEN** the complete destination inventory and custody transition is applied atomically

#### Scenario: Attempt mismatched approval
- **WHEN** receipt comparison fails
- **THEN** approval is blocked, the movement remains `PENDING`, and no inventory, serial, custody, claim, history, or header effect occurs

#### Scenario: Explicitly reject discrepancy
- **WHEN** an approver rejects a pending receipt with a reason
- **THEN** the rejected attempt remains immutable and a new empty superseding correction draft may be created

### Requirement: Receipt approval atomically adds destination inventory
Approved forward receipt SHALL add exactly the source dispatch's immutable applied quantities to corresponding destination good or broken tax/non-tax buckets, update global product totals, persist receipt transaction references and snapshots, approve the movement, and project an eligible no-return transfer to `COMPLETED` in one idempotent transaction.

#### Scenario: Receive good stock
- **WHEN** an exact `GOOD` receipt is approved
- **THEN** destination good buckets increase by the source dispatch's exact applied non-tax and tax quantities while broken buckets remain unchanged

#### Scenario: Receive broken stock
- **WHEN** an exact `BREAKAGE` receipt is approved
- **THEN** destination broken buckets increase by the source dispatch's exact applied broken non-tax and tax quantities while good buckets remain unchanged

#### Scenario: Approval fails after processing begins
- **WHEN** any later stock, transaction, serial, custody, claim, history, or header operation fails
- **THEN** every receipt approval effect rolls back

#### Scenario: Replay approved receipt
- **WHEN** the same approval is repeated with the same idempotency identity
- **THEN** destination stock, transactions, serial history, custody, claims, movement history, and header projection are applied at most once

### Requirement: Exact receipt closes serialized transit custody
Approved serialized receipt SHALL move every exact live serial to the destination, close its movement custody, remove its active transfer claim, and record immutable serial history atomically; no other receipt state SHALL change custody.

#### Scenario: Approve exact serialized receipt
- **WHEN** every received serial exactly matches the source dispatch and owns the expected active claim
- **THEN** each live serial location becomes the destination, custody becomes `CLOSED`, and its active claim is removed

#### Scenario: Receipt remains pending or is rejected
- **WHEN** a serialized receipt is pending, mismatched, rejected, corrected, cancelled, or fails approval
- **THEN** dispatched serials remain exclusively in transit with live location at the origin

#### Scenario: Concurrent receipt approval
- **WHEN** competing requests try to close the same custody claims
- **THEN** locking and idempotency permit at most one complete destination application

### Requirement: Delivery 6 activates only deterministic no-return routes
The system SHALL assign and operate workflow version `2` only for same-business and cross-business non-PKP-to-non-PKP transfers, and SHALL keep every PKP-involved route on workflow version `1` until Delivery 7.

#### Scenario: Create eligible same-business transfer
- **WHEN** a new transfer has origin and destination under the same business
- **THEN** the system may assign workflow version `2` and complete it through approved forward receipt

#### Scenario: Create eligible cross-business non-PKP transfer
- **WHEN** both origin and destination businesses are non-PKP
- **THEN** the system may assign workflow version `2` and complete it without a return obligation

#### Scenario: Create PKP-involved transfer
- **WHEN** either business is PKP
- **THEN** the system assigns workflow version `1` and does not expose version `2` movement actions

#### Scenario: Craft ineligible version 2 request
- **WHEN** a user invokes version `2` dispatch or receipt for a PKP-involved route
- **THEN** the system rejects it without movement, inventory, custody, claim, or header effects

### Requirement: Version 3 receiving uses a single explicit confirmation
For version 3 only, receiving SHALL display the dispatched product list and quantities without route configuration and offer Terima Barang followed by a Bahasa Indonesia confirmation modal. Confirmation SHALL state that all listed goods must have been counted and received in full. The receiver SHALL not enter quantities, scan goods, submit a count, or seek a second receipt approval. The acting user SHALL hold receiving permission in the active business; self-receiving SHALL be allowed.

#### Scenario: Open receipt confirmation
- **WHEN** an authorized receiver clicks Terima Barang for a dispatched document
- **THEN** the modal says "Pastikan seluruh barang telah dihitung dan jumlahnya sesuai dengan daftar pada dokumen ini. Dengan mengonfirmasi, Anda menyatakan seluruh barang telah diterima lengkap." and offers Batal and Konfirmasi Penerimaan

#### Scenario: Confirm full receipt
- **WHEN** the receiver confirms a still-dispatched document
- **THEN** all approved destination effects commit atomically, receiver and time are recorded, and status becomes COMPLETED

#### Scenario: Dismiss confirmation or goods are incomplete
- **WHEN** the receiver dismisses the modal or does not confirm complete delivery
- **THEN** the document stays DISPATCHED and no receiving stock is posted

#### Scenario: Legacy receipt remains operational
- **WHEN** a version 1 or 2 document requires receipt after this change
- **THEN** its existing receiving rules remain authoritative

### Requirement: Version 3 receipt has no partial or mismatch workflow
Version 3 SHALL support one complete receipt only and SHALL not create blind counts, recount attempts, mismatch flags, partial receipts, or discrepancy-acceptance paths. A confirmation SHALL represent the actor's declaration of complete delivery, not independently recorded count evidence.

#### Scenario: Attempt partial receipt
- **WHEN** a client tries to receive a subset of a version 3 dispatch
- **THEN** no partial destination movement or partially received status is permitted

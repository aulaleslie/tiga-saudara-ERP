# stock-transfer-return-dispatch Specification

## Purpose
Partial, multi-product, scanner-first return-dispatch batches for mandatory-return stock transfers, each with an independent approval lifecycle and an immutable approved manifest, while return-receipt execution remains dormant pending a later delivery.

## Requirements

### Requirement: Return dispatch supports partial multi-product batches
An authorized destination dispatcher SHALL be able to create a return-dispatch batch containing a positive quantity of one or more products with outstanding obligations, without being required to dispatch every product or the full outstanding transfer quantity at once.

#### Scenario: Dispatch one obligated product partially
- **WHEN** a transfer has ten outstanding units and the operator prepares four eligible units
- **THEN** the batch may be submitted without including the other six units

#### Scenario: Dispatch several products together
- **WHEN** the operator physically prepares eligible quantities for several obligated products
- **THEN** one return-dispatch batch records their canonical product quantities under the transfer condition

#### Scenario: Submit an empty batch
- **WHEN** a return-dispatch draft has no positive product observation
- **THEN** submission is rejected without reserving obligation capacity or changing inventory

### Requirement: Return dispatch preparation is scanner-first and authoritative
Return-dispatch scans and manual non-serialized observations MUST resolve canonical tenant-eligible products and conversions on the server, and submission MUST revalidate product, condition, transfer revision, route policy, source receipt, and current obligation capacity.

#### Scenario: Scan an eligible non-serialized product
- **WHEN** a valid product or conversion barcode is scanned at the return-dispatch location
- **THEN** its normalized base-unit observation is accumulated on one canonical movement line

#### Scenario: Observe a product without an obligation
- **WHEN** an operator attempts to add a product or condition absent from the transfer's obligations
- **THEN** the observation cannot be submitted as part of the return batch

#### Scenario: Capacity changes before submission
- **WHEN** another batch consumes available obligation capacity before this draft is submitted
- **THEN** submission revalidates current capacity and rejects the stale excess without inventory effects

### Requirement: Serialized returns permit eligible substitutes
A serialized return-dispatch line SHALL derive its quantity only from distinct selected live serials and SHALL NOT require those serials to equal the forward-dispatch or forward-receipt serial identities.

#### Scenario: Select a different eligible serial
- **WHEN** a live serial differs from the forward-leg serial but matches the obligated product and condition, is at the return-dispatch location, has compatible destination tax classification, and is operationally available
- **THEN** it may be selected for the return batch

#### Scenario: Serialized quantity is entered manually
- **WHEN** an operator attempts to set a positive serialized quantity without selecting the same number of distinct eligible serials
- **THEN** the mutation is rejected

#### Scenario: Selected serial becomes unavailable
- **WHEN** a selected serial changes location, product, condition, tax classification, identity, status, reservation, or custody before submission or approval
- **THEN** the boundary rejects the batch without partial effects

### Requirement: Each return batch has an independent approval lifecycle
Each return-dispatch lineage SHALL support draft editing, submission, approval, rejection, cancellation, and superseding correction independently, and submitted attempts and approved manifests MUST remain immutable and auditable.

#### Scenario: Approve one of several batches
- **WHEN** one pending return-dispatch batch passes comparison and authoritative validation
- **THEN** only that batch is approved and other draft, pending, or approved batch lineages remain independent

#### Scenario: Reject and correct a batch
- **WHEN** an approver rejects a pending batch with a reason and an authorized operator starts correction
- **THEN** a new draft supersedes the rejected attempt while preserving the rejected manifest and history

#### Scenario: Replay approval
- **WHEN** the same completed approval action is retried with the same scoped idempotency key
- **THEN** the existing approved result is returned without duplicate stock, reservation, custody, transaction, or history effects

### Requirement: Approved return dispatch persists the exact receipt manifest
Approval SHALL freeze the batch's product quantities, condition, selected serial identities, inventory allocation, source lineage, actors, and timestamps as the exact manifest for one independently approved return receipt.

#### Scenario: Batch contains substitute serials
- **WHEN** a serialized return-dispatch batch is approved
- **THEN** its selected substitute serial set becomes the expected serial set for that batch's later receipt

#### Scenario: Later receipt attempts to combine batches
- **WHEN** a return receipt references observations from more than one approved return-dispatch movement
- **THEN** it cannot be treated as the exact receipt for either source manifest

### Requirement: Return-receipt execution remains dormant
This delivery MUST NOT expose return-receipt preparation, approval, origin inventory addition, obligation fulfillment, or final completion mutations.

#### Scenario: Approved return dispatch awaits receipt delivery
- **WHEN** a return-dispatch batch is approved
- **THEN** it remains an immutable in-transit manifest and no origin inventory or returned obligation quantity changes

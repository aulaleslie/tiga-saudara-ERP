# stock-opname-review-approval Specification

## Purpose
Give Stock Opname an explicit, permission-aware review and approval lifecycle: counters submit completed drafts for approval rather than mutating inventory directly, reviewers with stock-visibility permission see full reconciliation (quantities, serials, tax classification) before deciding, and approval atomically applies only the entered scope while preserving an explainable, immutable audit trail — all within active-setting ownership boundaries.

## Requirements

### Requirement: Stock Opname uses an explicit approval lifecycle
The system SHALL persist a newly created or edited normal versioned Stock Opname as `draft` without notifying approvers. An authorized user SHALL explicitly submit a valid nonempty draft to `waiting_approval`, after which ordinary editing is locked and approval notification is sent. Only a `waiting_approval` document SHALL be approved or rejected, and lifecycle actions SHALL be idempotent.

#### Scenario: Save counting work without submitting
- **WHEN** an authorized counter creates or edits a valid Stock Opname
- **THEN** the document is stored as `draft`, remains editable, and produces no approval-needed notification

#### Scenario: Submit a draft for approval
- **WHEN** an authorized user explicitly submits a valid nonempty draft
- **THEN** the system records the submitter and submission time, changes the status to `waiting_approval`, locks ordinary editing, and notifies eligible approvers once

#### Scenario: Attempt a decision in the wrong state
- **WHEN** a user attempts to approve or reject a document that is not `waiting_approval`
- **THEN** the system rejects the action without changing document, stock, serial, transaction, or notification state

### Requirement: Stock Opname presentation is permission-aware and Indonesian
All Stock Opname user-facing labels, statuses, warnings, validations, confirmations, notifications, and audit messages SHALL use Bahasa Indonesia. A user without `adjustments.view-system-stock` SHALL see only document metadata and the product identities, good/bad quantities, serial text, and conditions entered in the document. A user with that permission SHALL additionally receive system-stock and reconciliation information. Mutation actions SHALL still require their dedicated permissions.

#### Scenario: Counter views a document
- **WHEN** a user with document-view access but without `adjustments.view-system-stock` opens a Stock Opname
- **THEN** the page shows what was entered and does not disclose baselines, live stock, other-location totals, serial registration or source, tax state, differences, warnings derived from system stock, or projected effects

#### Scenario: Reviewer views a document
- **WHEN** a user with document-view access and `adjustments.view-system-stock` opens a Stock Opname
- **THEN** the page presents the permitted reconciliation information with Bahasa Indonesia labels and separates captured baseline, current state, entered count, and projected or applied result

#### Scenario: Reviewer lacks approval authority
- **WHEN** a permitted stock reviewer lacks `adjustments.approval`
- **THEN** the reviewer can inspect reconciliation information but cannot approve or reject the document

### Requirement: Reviewer sees current product reconciliation
For every entered serialized or non-serialized product, the system SHALL show permitted reviewers the captured and current good/bad quantities per selected location, their selected-pool sums, entered good/bad quantities, signed differences, proposed per-location allocations, projected global total, and stock drift. Good and damaged differences SHALL be reconciled independently. The presentation SHALL clearly distinguish condition reclassification from net quantity change and SHALL identify allocation changes caused by stock drift since counting began.

#### Scenario: Count exceeds all eligible location stock
- **WHEN** selected locations contain a current total of 10 units and the entered physical total is 12
- **THEN** the reviewer sees a selected-pool surplus of 2, the projected global increase, and the exact selected location that will receive it

#### Scenario: Good stock is reclassified as bad
- **WHEN** the selected pool currently has good 10 and damaged 1 while the entered count is good 8 and damaged 3
- **THEN** the reviewer sees good minus 2 and damaged plus 2 with separate per-location allocation plans
- **AND** the reviewer is informed that total stock is unchanged by the condition reclassification

#### Scenario: Stock changed after counting began
- **WHEN** current stock at any selected location differs from its captured baseline
- **THEN** the reviewer sees the per-location drift warning
- **AND** the proposed allocation is recalculated from current authoritative stock rather than the captured baseline

### Requirement: Reviewer sees serial reconciliation classifications
The system SHALL re-resolve entered serialized product values and classify each serial relative to the selected location pool as retained at its selected current location, moving into the pool from another location, newly registered at a deterministic selected destination, changing condition, changing tax classification, omitted from the selected pool, or conflicting. A serial already at a selected location SHALL remain there unless its condition changes. An entered serial outside the selected pool and a new serial SHALL use the same surplus destination priority as its condition. Registered available serials currently in any selected location and omitted from the entered complete serial set SHALL be identified as missing from their actual location.

#### Scenario: Existing serial is already in the selected pool
- **WHEN** an entered serial currently belongs to one of the selected locations
- **THEN** approval retains it at that location and applies only any entered condition change

#### Scenario: Existing serial belongs to another location
- **WHEN** an entered serial currently belongs to an eligible location outside the selected pool
- **THEN** the reviewer sees its current location and deterministic selected destination
- **AND** approval moves it only after the source and destination are revalidated

#### Scenario: Entered serial is new
- **WHEN** no serial with the entered text exists for that product
- **THEN** the reviewer sees the deterministic selected destination where approval will register it

#### Scenario: Destination serial is omitted
- **WHEN** a registered available serial at any selected location is absent from the submitted complete serial set
- **THEN** the reviewer sees it as missing from its actual location and sees its proposed approval disposition

#### Scenario: Serial has an unsafe active conflict
- **WHEN** current authoritative serial state is incompatible with retention, movement, creation, reclassification, or omission
- **THEN** the preview identifies a conflict and approval is blocked without inventory mutation

### Requirement: Destination PKP governs projected and applied tax classification
The system SHALL preserve the tax classification of stock that remains at a selected location and SHALL derive every deduction, addition, moved serial, and newly created serial from the current `is_pkp` value of the location where that effect is applied. It SHALL ignore client-provided tax state and SHALL show permitted reviewers every projected tax classification change. Locations whose setting has `is_pkp = true` SHALL always be processed after eligible non-PKP selected locations for both shortage and surplus allocation.

#### Scenario: Shortage reaches a PKP location only after non-PKP stock
- **WHEN** selected non-PKP and PKP locations both contain enough stock to contribute to a shortage
- **THEN** approval exhausts the ordered eligible non-PKP stock before deducting any stock from a PKP location

#### Scenario: Surplus has non-PKP and PKP destinations
- **WHEN** selected non-PKP and PKP locations are eligible to receive a surplus
- **THEN** approval selects the eligible non-PKP location with the least relevant stock before considering any PKP location
- **AND** the added stock inherits the chosen location's tax classification

#### Scenario: Every selected location is PKP
- **WHEN** all eligible selected locations are PKP
- **THEN** the system applies the normal stock ordering and location-ID tie-breaker within the PKP group

#### Scenario: Taxable serial moves to a non-PKP location
- **WHEN** a taxable serial outside the selected pool is assigned to a non-PKP selected destination
- **THEN** the reviewer sees `Kena Pajak → Tidak Kena Pajak` and approval decrements the correct source bucket and increments the destination non-tax bucket without changing global quantity

#### Scenario: Non-tax serial moves to a PKP location
- **WHEN** a non-tax serial outside the selected pool is assigned to a PKP selected destination after all eligible non-PKP destinations have been exhausted or are absent
- **THEN** the reviewer sees `Tidak Kena Pajak → Kena Pajak` and approval applies the corresponding source and destination bucket changes

#### Scenario: New serial inherits destination classification
- **WHEN** approval creates an entered serial that is not registered for the product
- **THEN** it is classified from the authoritative PKP status of its deterministic selected destination

### Requirement: Approval applies only explicit entered scope atomically
Approval SHALL revalidate and lock the document, all selected locations and their settings, entered products, affected product stocks, and relevant serials in one database transaction using a stable global lock order. For each non-serialized product and each condition independently, a shortage SHALL be deducted through a waterfall ordered by `is_pkp` ascending, current relevant stock descending, and location ID ascending; a surplus SHALL be assigned to the first eligible location ordered by `is_pkp` ascending, current relevant stock ascending, and location ID ascending. Approval SHALL leave omitted products and unselected ordinary-product locations unchanged, update product aggregates, create per-location inventory transactions, and emit stock notifications consistently with the net applied changes.

#### Scenario: Allocate a shortage across multiple locations
- **WHEN** the entered physical count is lower than the selected-pool system count by more than the first priority location can supply
- **THEN** approval reduces that location to zero for the relevant condition and continues through the ordered locations until the shortage is fully allocated
- **AND** no location quantity becomes negative

#### Scenario: Allocate a surplus to the least-stock non-PKP location
- **WHEN** physical stock exceeds selected-pool system stock and at least one non-PKP selected location is eligible
- **THEN** approval assigns the entire condition-specific surplus to the non-PKP location with the least current relevant stock
- **AND** uses ascending location ID to resolve an equal-stock tie

#### Scenario: Apply independent condition differences
- **WHEN** the entered good quantity is lower and the entered damaged quantity is higher than their selected-pool system quantities
- **THEN** approval applies the good shortage and damaged surplus using separate ordered allocations
- **AND** records both effects even if they affect different selected locations

#### Scenario: Approve a partial-product document
- **WHEN** products A and C are entered while products B and D also exist in selected locations
- **THEN** approval applies A and C and leaves B and D unchanged

#### Scenario: Approve a partial-location document
- **WHEN** an entered product exists at several selected locations but its difference affects only the first locations required by the allocation waterfall
- **THEN** approval mutates only those allocated locations and leaves the remaining selected-location rows unchanged

#### Scenario: Move an entered serial
- **WHEN** an entered serial is authoritatively found outside the selected pool and has a valid deterministic destination
- **THEN** the system updates the correct source buckets, moves the serial, applies its proposed condition and destination tax classification, and leaves global product quantity unchanged

#### Scenario: Create an unknown entered serial
- **WHEN** an entered serial remains unknown for its product during locked approval
- **THEN** the system creates it at its deterministic selected destination with the proposed condition and destination tax classification and increases location and global quantity consistently

#### Scenario: Approval fails after a planned mutation
- **WHEN** any location, reconciliation, serial, stock, transaction, notification preparation, or audit operation fails during approval
- **THEN** the entire approval rolls back and the document remains `waiting_approval` with no partial inventory effect

### Requirement: Rejection and approval retain explainable history
Rejection SHALL require a Bahasa Indonesia reason and record the rejecting user and time. Editing a rejected document SHALL return it to `draft` and require resubmission. Successful approval SHALL persist an immutable applied-result snapshot containing the selected location set, per-location before and after quantities, entered selected-pool counts, allocation order and effects, source/destination, condition, tax, quantity, serial, actor, and timestamp evidence needed to explain what occurred later.

#### Scenario: Reject a submitted proposal
- **WHEN** an authorized approver rejects a waiting proposal with a nonempty reason
- **THEN** the system records the reason, actor, and timestamp without mutating inventory

#### Scenario: Reject without a reason
- **WHEN** an approver attempts to reject a submitted Stock Opname without a nonempty reason
- **THEN** the system rejects the action and leaves the document and inventory unchanged

#### Scenario: Revise a rejected document
- **WHEN** an authorized counter edits and saves a rejected multi-location Stock Opname
- **THEN** the system returns it to `draft`, clears the prior rejection decision as lifecycle policy requires, and requires resubmission

#### Scenario: View an approved document after stock changes again
- **WHEN** selected-location stock changes after a multi-location stock opname has been approved
- **THEN** its detail page continues to show the immutable per-location allocation and applied result captured at approval

### Requirement: Lifecycle operations enforce active-setting ownership
The system SHALL verify the required permission for show, edit, update, delete, submit, approve, and reject operations. Viewing an adjustment document SHALL require `adjustments.show` without requiring complete selected-location eligibility, allowing existing documents to remain viewable for audit and history. Mutation operations (edit, update, delete, submit, approve, reject) SHALL verify the complete eligibility of every selected location. Stock-opname location eligibility SHALL NOT depend on the user's current active setting. Product and location resolution for mutations SHALL reject inactive, nonexistent, duplicate, and consignment locations, and failure of any selected location SHALL reject the mutation operation without exposing unauthorized stock or partially mutating the document.

#### Scenario: Active setting differs from selected locations
- **WHEN** an authorized user performs a lifecycle action on a document whose eligible selected locations belong to other settings
- **THEN** the action is evaluated against its permission and complete selected location set rather than rejected because of the active setting

#### Scenario: Cross-setting document access
- **WHEN** an authorized user opens or acts on a multi-location Stock Opname spanning settings different from the active setting
- **THEN** the system permits the action when every selected location is eligible and the user holds the required action permission

#### Scenario: One selected location becomes ineligible
- **WHEN** any selected location is inactive, deleted, consignment, or otherwise unauthorized at an authoritative lifecycle boundary for a mutation operation
- **THEN** the mutation operation is rejected without inventory mutation or disclosure from that location

#### Scenario: Permission is insufficient
- **WHEN** a user without the permission required for a requested stock-opname lifecycle action targets a multi-location document
- **THEN** the system denies the action regardless of active setting or location ownership

## ADDED Requirements

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
For every entered serialized or non-serialized product, the system SHALL show permitted reviewers the selected-location captured baseline, current good/bad quantities, entered good/bad quantities, signed differences, current total across eligible same-owner non-consignment locations, projected global total, and stock drift. It SHALL clearly distinguish condition reclassification from net quantity change and SHALL warn when the entered selected-location total exceeds the current all-location total.

#### Scenario: Count exceeds all eligible location stock
- **WHEN** an entered product has 12 total units at the selected location while its current total across all eligible owner locations is 10
- **THEN** the reviewer sees a Bahasa Indonesia warning identifying a potential global increase of 2 units

#### Scenario: Good stock is reclassified as bad
- **WHEN** current selected-location stock is good 10 and bad 1 while the entered count is good 8 and bad 3
- **THEN** the reviewer sees good minus 2 and bad plus 2 and is informed that total stock is unchanged by the condition reclassification

#### Scenario: Stock changed after counting began
- **WHEN** current selected-location or all-location stock differs from the captured baseline
- **THEN** the reviewer sees a drift warning and the projected result is calculated from current authoritative stock rather than presented as the original baseline

### Requirement: Reviewer sees serial reconciliation classifications
The system SHALL re-resolve entered serialized product values and classify each serial for permitted reviewers as already at the destination, moving from another location, new for the product, changing condition, changing tax classification, or conflicting. It SHALL also list registered available serials currently at the destination that were omitted from the entered complete serial set.

#### Scenario: Existing serial belongs to another location
- **WHEN** an entered serial for the product currently belongs to another eligible location
- **THEN** the reviewer sees its current location, selected destination, and a clear statement that approval will move it

#### Scenario: Entered serial is new
- **WHEN** no serial with the entered text exists for that product
- **THEN** the reviewer is informed that approval will register a new serial at the selected location

#### Scenario: Destination serial is omitted
- **WHEN** a serialized product is entered and an available serial currently at the selected location is absent from the submitted serial set
- **THEN** the reviewer sees that serial as a discrepancy and its proposed approval disposition before deciding

#### Scenario: Serial has an unsafe active conflict
- **WHEN** current authoritative serial state is incompatible with movement, creation, reclassification, or removal
- **THEN** the preview identifies a conflict and approval is blocked without inventory mutation

### Requirement: Destination PKP governs projected and applied tax classification
The system SHALL derive all resulting good/bad tax buckets and serial tax classification from the current `is_pkp` value of the selected destination location's setting. It SHALL ignore client-provided tax state and SHALL show permitted reviewers every projected tax classification change.

#### Scenario: Taxable serial moves to a non-PKP location
- **WHEN** a taxable serial is entered for a destination whose setting is Non-PKP
- **THEN** the reviewer sees `Kena Pajak → Tidak Kena Pajak` and approval decrements the correct source tax bucket and increments the destination non-tax bucket without changing global quantity

#### Scenario: Non-tax serial moves to a PKP location
- **WHEN** a non-tax serial is entered for a destination whose setting is PKP
- **THEN** the reviewer sees `Tidak Kena Pajak → Kena Pajak` and approval applies the corresponding source and destination bucket changes

#### Scenario: New serial inherits destination classification
- **WHEN** approval creates an entered serial that is not registered for the product
- **THEN** it is created as tax stock at a PKP destination or non-tax stock at a Non-PKP destination

### Requirement: Approval applies only explicit entered scope atomically
Approval SHALL revalidate and lock the document, destination location, entered products, affected product stocks, and relevant serials in one database transaction. It SHALL set each entered product's selected-location good/bad quantities to the absolute submitted counts, leave omitted products unchanged, leave other locations unchanged for ordinary products, and mutate another location only when reconciling an entered serial currently located there. It SHALL update product aggregates, inventory transactions, and notifications consistently with the net applied changes.

#### Scenario: Approve a partial-location document
- **WHEN** products A and C are entered for a location that also stocks products B and D
- **THEN** approval applies A and C and leaves B and D unchanged

#### Scenario: Move an entered serial
- **WHEN** an entered serial is authoritatively found at another eligible location during locked approval
- **THEN** the system updates the correct source buckets, moves the serial, applies its proposed condition and destination tax classification, updates destination buckets, and leaves global product quantity unchanged

#### Scenario: Create an unknown entered serial
- **WHEN** an entered serial remains unknown for its product during locked approval
- **THEN** the system creates it at the destination with the proposed condition and destination tax classification and increases location and global quantity consistently

#### Scenario: Approval fails after a planned mutation
- **WHEN** any reconciliation, serial, stock, transaction, or audit operation fails during approval
- **THEN** the entire approval rolls back and the document remains `waiting_approval` with no partial inventory effect

### Requirement: Rejection and approval retain explainable history
Rejection SHALL require a Bahasa Indonesia reason and record the rejecting user and time. Editing a rejected document SHALL return it to `draft` and require resubmission. Successful approval SHALL persist an immutable applied-result snapshot containing before, entered, applied, source/destination, condition, tax, quantity, serial, actor, and timestamp evidence needed to explain what occurred later.

#### Scenario: Reject without a reason
- **WHEN** an approver attempts to reject a submitted Stock Opname without a reason
- **THEN** the system returns Bahasa Indonesia validation feedback and leaves the document in `waiting_approval`

#### Scenario: Revise a rejected document
- **WHEN** an authorized counter edits and saves a rejected Stock Opname
- **THEN** it becomes `draft`, retains the prior rejection evidence for review, and is not approval-ready until explicitly resubmitted

#### Scenario: View an approved document after stock changes again
- **WHEN** a permitted reviewer opens an approved Stock Opname after later inventory activity
- **THEN** the page shows the immutable result actually applied by that approval rather than reconstructing it from current stock alone

### Requirement: Lifecycle operations enforce active-setting ownership
The system SHALL verify both permission and active-setting ownership for show, edit, update, delete, submit, approve, and reject operations. Product and location resolution SHALL exclude unrelated settings and SHALL prevent a consignment location from becoming the Stock Opname destination.

#### Scenario: Cross-setting document access
- **WHEN** a user attempts a Stock Opname read or lifecycle operation on a document whose destination belongs to another active setting
- **THEN** the system denies the operation without disclosing reconciliation data or mutating state


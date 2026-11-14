# Spec Delta

## ADDED Requirements

### Requirement: Zeroing stock is guarded at submission and approval
For every entered product, submission and approval SHALL require valid explicit physical-zero confirmation when its current selected-pool system total is positive and its entered good plus bad total is zero. The server SHALL enforce this independently of UI controls, using authoritative stock at each boundary. Failure SHALL identify the affected product without committing lifecycle or inventory effects.

#### Scenario: Default zero on positive stock
- **WHEN** a serialized or ordinary product has positive selected-pool stock and an unconfirmed entered total of zero
- **THEN** submission is rejected and the document remains draft
- **AND** no approval notification or inventory mutation occurs

#### Scenario: Confirmed empty product
- **WHEN** valid physical-zero confirmation covers the unchanged zero count and selected locations
- **THEN** the zero safeguard permits submission and approval subject to existing permissions and inventory conflict checks

#### Scenario: Stock becomes positive after submission
- **WHEN** an unconfirmed zero row was submitted while selected-pool stock was zero and that stock is positive at approval
- **THEN** approval is blocked without inventory or decision changes
- **AND** the document requires rejection, editing to confirm or correct the count, and resubmission

#### Scenario: No zeroing effect
- **WHEN** a product's entered total is positive or both its current selected-pool stock and entered total are zero
- **THEN** the total-zero safeguard does not require confirmation
- **AND** existing partial-reduction and condition-reclassification checks still apply

### Requirement: Existing unapproved documents cannot imply zero intent
Unapproved documents without valid confirmation SHALL be treated as unconfirmed at submission and approval. Pending approvals that require confirmation SHALL use the existing reject, edit, and resubmit lifecycle; approvers MUST NOT add confirmation while approving. Approved historical documents SHALL remain unchanged and readable.

#### Scenario: Older pending document contains zero
- **WHEN** an existing pending document would zero positive stock but has no confirmation
- **THEN** approval is blocked with guidance to reject for count correction or explicit confirmation and resubmission

#### Scenario: Older draft is corrected
- **WHEN** an existing draft or rejected document is edited to confirm physical zero and resubmitted
- **THEN** it follows the same safeguard and audit rules as a new document

#### Scenario: Historical approval is opened
- **WHEN** an already approved document has no zero-confirmation properties
- **THEN** it remains readable from its existing historical evidence without synthesized confirmation or rewritten results

### Requirement: Serialized counts reconcile unmatched stock quantities
Within the selected pool, serialized reconciliation SHALL account for quantity not represented by stock-bearing serials, independently by location, condition, and tax bucket. A confirmed zero SHALL remove eligible unmatched quantity. Entered new physical serials SHALL account for existing unmatched units before creating additional quantity. Protected or inconsistent stock MUST NOT be treated as freely removable unmatched quantity.

#### Scenario: Confirmed zero removes the unmatched unit
- **WHEN** a selected location has one good unit without a stock-bearing serial and the physical total is confirmed zero
- **THEN** approval reduces that location to zero and decreases global quantity by one
- **AND** unrelated stock and serials at unselected locations are unchanged

#### Scenario: New physical serial identifies an existing unit
- **WHEN** selected-pool stock has one eligible unmatched good unit and the physical count supplies one new good serial
- **THEN** approval registers that serial and accounts for the existing unit without increasing global quantity
- **AND** preview and history distinguish identity registration from a stock surplus

#### Scenario: Only part of a physical count is surplus
- **WHEN** the selected pool has two eligible unmatched good units and the physical count supplies three new good serials
- **THEN** approval registers all three serials, accounts for two existing units, and increases stock by only one

#### Scenario: Registered and unmatched stock coexist
- **WHEN** a selected location has quantity two represented by one available serial and one unmatched unit, and only the existing serial is physically counted
- **THEN** approval retains that serial and removes only the unmatched unit, leaving quantity one
- **AND** the positive physical total does not require total-zero confirmation

#### Scenario: Another location owns the physical unit
- **WHEN** Perdana has one unmatched unit, White Knight has one valid available serial-backed unit, and only Perdana is selected and confirmed empty
- **THEN** approval produces Perdana zero, White Knight one, and global quantity one
- **AND** it does not consume or relocate White Knight's serial

#### Scenario: Existing reservations or custody protect stock
- **WHEN** reconciliation would remove reserved stock, alter protected serial custody, make a bucket negative, or rely on contradictory stock coverage
- **THEN** existing guards or an explicit reconciliation conflict block approval atomically
- **AND** the system does not silently clamp the inconsistency or classify protected serial-backed stock as unmatched

### Requirement: Zero intent and unmatched corrections are reviewable
Existing document review SHALL show entered zero confirmation with its confirmer and time. Stock-authorized reviewers SHALL additionally see unmatched quantities, planned corrections, and their net stock effects. Approval SHALL retain dedicated confirmation and reconciliation evidence in its immutable result. Users without stock visibility MUST NOT receive stock-derived values through these additions.

#### Scenario: Reviewer assesses zeroing
- **WHEN** a stock-authorized reviewer opens a confirmed zero proposal
- **THEN** the existing review shows “Stok fisik dikonfirmasi kosong”, confirmer, time, and the projected per-location reduction

#### Scenario: Counter has no stock visibility
- **WHEN** a counter without system-stock visibility views or confirms a zero row
- **THEN** confirmation controls and messages do not disclose whether system stock is positive, unmatched quantities, or projected corrections
- **AND** the checkbox is available for zero rows without relying on visible system quantities

#### Scenario: Applied correction remains explainable
- **WHEN** a serialized correction is approved and stock later changes
- **THEN** document history retains confirmation attribution, unmatched quantities before correction, quantities accounted for by new serials, and actual per-location before/after values
- **AND** these values are not reconstructed from later live stock

## MODIFIED Requirements

### Requirement: Stock Opname presentation is permission-aware and Indonesian
All Stock Opname user-facing labels, statuses, warnings, validations, confirmations, notifications, and audit messages SHALL use Bahasa Indonesia. A user without `adjustments.view-system-stock` SHALL see only document metadata and the product identities, good/bad quantities, serial text, conditions, and explicit physical-zero confirmation with its confirmer and time entered in the document. A user with that permission SHALL additionally receive system-stock and reconciliation information. Mutation actions SHALL still require their dedicated permissions.

#### Scenario: Counter views a document
- **WHEN** a user with document-view access but without `adjustments.view-system-stock` opens a Stock Opname
- **THEN** the page shows what was entered and does not disclose baselines, live stock, other-location totals, serial registration or source, tax state, differences, warnings derived from system stock, or projected effects

#### Scenario: Reviewer views a document
- **WHEN** a user with document-view access and `adjustments.view-system-stock` opens a Stock Opname
- **THEN** the page presents the permitted reconciliation information with Bahasa Indonesia labels and separates captured baseline, current state, entered count, and projected or applied result

#### Scenario: Reviewer lacks approval authority
- **WHEN** a permitted stock reviewer lacks `adjustments.approval`
- **THEN** the reviewer can inspect reconciliation information but cannot approve or reject the document

### Requirement: Approval applies only explicit entered scope atomically
Approval SHALL revalidate and lock the document, all selected locations and their settings, entered products, affected product stocks, and relevant serials in one database transaction using a stable global lock order. For each non-serialized product and each condition independently, a shortage SHALL be deducted through a waterfall ordered by `is_pkp` ascending, current relevant stock descending, and location ID ascending; a surplus SHALL be assigned to the first eligible location ordered by `is_pkp` ascending, current relevant stock ascending, and location ID ascending. For serialized products, approval SHALL reconcile eligible unmatched quantity with the complete entered serial count, preserve protected serial-backed quantities, and enforce explicit-zero confirmation before zeroing positive stock. Approval SHALL leave omitted products and unselected ordinary-product locations unchanged, update product aggregates, create per-location inventory transactions, and emit stock notifications consistently with the net applied changes.

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
- **THEN** the system creates it at its deterministic selected destination with the proposed condition and destination tax classification and first accounts for eligible unmatched stock in the selected pool; only the remaining physical surplus increases location and global quantity

#### Scenario: Approval fails after a planned mutation
- **WHEN** any location, reconciliation, serial, stock, transaction, notification preparation, or audit operation fails during approval
- **THEN** the entire approval rolls back and the document remains `waiting_approval` with no partial inventory effect

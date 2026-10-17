# Spec Delta

## MODIFIED Requirements

### Requirement: Source-typed Purchases prohibit incompatible lifecycle operations
The system SHALL visibly identify a Purchase generated from consignment billing and SHALL prohibit ordinary receiving, full commercial editing, deletion, archival, correction, return, or other operations that would break immutable consignment provenance unless a later explicitly specified workflow authorizes them. Payment creation, payment invalidation, read-only reporting, and balance reconciliation SHALL continue through existing permissioned Purchase behavior. Administrative date, note, invoice-reference, and attachment maintenance SHALL follow standard Purchase capabilities as specified below and SHALL NOT be classified as full commercial editing.

#### Scenario: User attempts ordinary receiving
- **WHEN** a user invokes a Purchase receiving action for a consignment-billing Purchase
- **THEN** the system SHALL reject it without creating a Received Note or changing inventory

#### Scenario: User attempts provenance-breaking edit
- **WHEN** a user attempts to change supplier, product, quantity, cost, tax, totals, or source identity on a generated consignment Purchase
- **THEN** the system SHALL reject the change without mutating the Purchase or its lineage

#### Scenario: Authorized payment is recorded
- **WHEN** an authorized user pays all or part of an eligible consignment-billing Purchase
- **THEN** the existing Purchase payment workflow SHALL update active payment evidence and live outstanding balance
- **AND** no Consignment allocation or inventory evidence SHALL change

## ADDED Requirements

### Requirement: Generated Purchases support standard administrative date maintenance
Existing and future consignment-billing Purchases SHALL support the same reporting-date and due-date actions as eligible standard Purchases, using the same permissions, status eligibility, tenant boundaries, global eligibility, archive rules, Super Admin behavior, reason requirements, validation, and audit semantics. The displayed document date SHALL use the standard effective reporting date; the original transaction date SHALL remain unchanged. Reporting-date changes SHALL NOT automatically recalculate due dates or change payment terms. Existing reports and due-date consumers SHALL retain standard date semantics.

#### Scenario: Set replace and clear reporting date
- **WHEN** an authorized user sets, replaces, or clears a consignment Purchase reporting-date override with a valid reason
- **THEN** the system SHALL persist the override and standard immutable audit together
- **AND** displayed effective dates SHALL reflect the override or original date after clearing without rewriting the original transaction date

#### Scenario: Replace due date independently
- **WHEN** an authorized user supplies a valid replacement due date and reason
- **THEN** the system SHALL update the authoritative due date and append the standard audit
- **AND** original date, reporting date, payment-term identity, amounts, and payments SHALL remain unchanged

#### Scenario: Combined date changes fail atomically
- **WHEN** either field in a combined reporting-date and due-date request fails authorization, validation, persistence, or audit creation
- **THEN** neither date change nor its audit SHALL persist

#### Scenario: Standard access restrictions apply
- **WHEN** an administrative date request fails the standard Purchase permission, tenant, global eligibility, or archive checks
- **THEN** the system SHALL deny it without mutating the Purchase or audit history

### Requirement: Generated Purchases support standard notes and invoice references
Existing and future consignment-billing Purchases SHALL permit editing note, supplier purchase number, and tax invoice number through the same authorized administrative actions as standard Purchases. Existing length, uniqueness, normalization, archive, tenant, and global access rules SHALL apply. Purchase changes SHALL NOT synchronize back to original consignment confirmation metadata or conversion evidence.

#### Scenario: Authorized metadata edit
- **WHEN** an authorized user changes any supported note or invoice-reference field with valid input
- **THEN** the system SHALL save the value on the Purchase and show it on subsequent Purchase reads
- **AND** the original confirmation, allocation lineage, and conversion audit SHALL remain unchanged

#### Scenario: Invalid or unauthorized edit
- **WHEN** a metadata edit violates standard access, uniqueness, or input validation rules
- **THEN** the system SHALL reject it without changing Purchase metadata or source evidence

### Requirement: Generated Purchases support standard attachment maintenance
Consignment-billing Purchase attachments SHALL follow the same upload and deletion permissions, ownership checks, validation, and applicable screen availability as standard Purchase attachments. Attachments originally copied from consignment billing SHALL be deletable from the Purchase under these rules. Deleting a Purchase attachment SHALL NOT delete separate source-confirmation attachments or modify historical conversion evidence.

#### Scenario: Upload attachment after conversion
- **WHEN** an authorized user uploads a valid attachment to an existing consignment Purchase
- **THEN** the attachment SHALL be available through the ordinary Purchase attachment workflow

#### Scenario: Delete original billing attachment
- **WHEN** an authorized user deletes a Purchase attachment originally copied during consignment billing conversion
- **THEN** the system SHALL remove that Purchase attachment using standard deletion behavior
- **AND** separate source-confirmation documents and conversion history SHALL remain unchanged

#### Scenario: Foreign attachment deletion is rejected
- **WHEN** a user targets media outside the Purchase attachment collection or belonging to another document, or lacks the required access
- **THEN** the system SHALL reject deletion and preserve the media

### Requirement: Administrative parity preserves commercial provenance
Administrative maintenance SHALL be available on existing and future consignment-generated Purchases in normal Purchase views and applicable Global Purchase Payments views wherever standard Purchases provide the same action. Informational copy SHALL distinguish editable administrative information from locked commercial details. These actions SHALL preserve supplier identity, product rows, quantities, costs, discounts, tax treatment, totals, source identity, allocation snapshots, conversion audit, inventory, serials, and payment evidence. Ordinary receiving, receiving cancellation, commercial correction, monetary editing, returns, deletion, and archival SHALL remain prohibited for consignment-billing Purchases.

#### Scenario: Existing converted Purchase is maintained
- **WHEN** an authorized user opens a previously converted Purchase through a supported normal or global screen
- **THEN** the same administrative capabilities as an equivalent standard Purchase SHALL be available without reconversion or historical data rewriting
- **AND** the interface SHALL explain that commercial details remain locked

#### Scenario: Protected commercial change accompanies metadata
- **WHEN** a request combines an allowed administrative edit with a prohibited commercial or source change
- **THEN** the system SHALL reject the prohibited mutation without partially persisting the combined change

#### Scenario: Administrative changes preserve operational facts
- **WHEN** any supported administrative action succeeds
- **THEN** commercial values, consignment source evidence, inventory, serials, and payment records SHALL remain unchanged
- **AND** standard permissioned payment creation, invalidation, and reconciliation SHALL remain available separately

## ADDED Requirements

### Requirement: Billing captures authoritative supplier invoice metadata
The system SHALL allow an authorized user to prepare billing metadata only for an approved, billing-ready Consignment Billing Confirmation in the active setting. The metadata SHALL include the supplier invoice/reference number, invoice date, reporting date when applicable, due date or payment term, optional supplier tax reference, notes, and supported attachments, and SHALL be validated against the locked confirmation supplier and setting before conversion.

#### Scenario: Valid supplier invoice is prepared
- **WHEN** an authorized user supplies valid invoice metadata for an approved billing-ready confirmation
- **THEN** the system SHALL present an exact conversion preview using that confirmation's immutable allocation evidence
- **AND** no Purchase, payable, payment, or inventory mutation SHALL occur before conversion is confirmed

#### Scenario: Foreign or ineligible confirmation is submitted
- **WHEN** billing metadata targets a foreign-setting, non-approved, non-ready, or already billed confirmation
- **THEN** the system SHALL reject the request without disclosing foreign data or changing any record

### Requirement: One confirmation converts to one Purchase exactly once
The system SHALL convert exactly one approved billing-ready confirmation into exactly one Purchase in a single database transaction. Conversion SHALL lock and revalidate the confirmation, supplier, allocation evidence, Purchase sequence, and absence of an existing Purchase link; concurrent or repeated conversion requests SHALL produce at most one Purchase and one set of Purchase details.

#### Scenario: Billing-ready confirmation is converted
- **WHEN** an authorized user confirms conversion with valid supplier invoice metadata
- **THEN** exactly one source-typed Purchase SHALL be created and linked to the confirmation
- **AND** the confirmation SHALL retain its approved allocation state and become no longer ready for another conversion

#### Scenario: Concurrent conversions target one confirmation
- **WHEN** two requests attempt to convert the same confirmation concurrently
- **THEN** at most one request SHALL create the Purchase and lineage records
- **AND** the other request SHALL return the already-linked result or fail without partial mutation

#### Scenario: Conversion fails on a later line
- **WHEN** any sequence, monetary, lineage, Purchase-detail, attachment, audit, or link operation fails
- **THEN** the Purchase, details, lineage, confirmation billing state, and audit mutations from that attempt SHALL all roll back

### Requirement: Purchase lines preserve exact consignment commercial provenance
The generated Purchase SHALL derive quantities, product identity, supplier unit cost, tax identity, and monetary amounts exclusively from approved Phase 2 receipt and serialized allocations backed by Phase 1 receiving snapshots. Commercially distinct receipt lots SHALL remain distinct Purchase details or retain equivalent lossless lineage, and every generated detail SHALL be durably traceable to the confirmation and contributing allocation evidence.

#### Scenario: One product uses receipt lots with different costs
- **WHEN** a confirmation allocates one product across receipt lots with different cost or tax snapshots
- **THEN** conversion SHALL preserve each distinct commercial snapshot without averaging or silently merging it
- **AND** the sum of generated detail quantities SHALL equal the approved confirmation quantity

#### Scenario: Serialized allocations are converted
- **WHEN** an approved serialized confirmation is converted
- **THEN** each billed serial SHALL remain traceable to its immutable sold source and receiving-detail lineage
- **AND** no serial SHALL be reassigned, duplicated, or have its operational status changed

#### Scenario: Allocation evidence is inconsistent
- **WHEN** approved allocation quantities, supplier identity, setting, product, receipt lineage, or stored snapshots do not reconcile exactly
- **THEN** conversion SHALL fail with actionable evidence and create no payable

### Requirement: Consignment billing is financially active and inventory inert
The generated Purchase SHALL establish the supplier payable using existing Purchase monetary and reference conventions and SHALL be marked physically complete with an explicit consignment-billing source classification. Conversion SHALL NOT create a Received Note or mutate physical stock, tax/non-tax stock buckets, ProductPrice, average or last purchase cost, serials, Sales, POS, dispatches, returns, or Phase 2 allocation quantities.

#### Scenario: Conversion creates a payable
- **WHEN** a valid confirmation is converted
- **THEN** the Purchase total, paid amount, due amount, payment status, supplier, dates, and tax totals SHALL reflect the approved billing evidence
- **AND** the Purchase SHALL be eligible for the existing authorized payment workflow

#### Scenario: Inventory state is compared before and after conversion
- **WHEN** conversion succeeds
- **THEN** stock, serial, cost, dispatch, return, receiving, and allocation quantities SHALL remain unchanged
- **AND** no ordinary or consignment receiving note SHALL be created

### Requirement: Source-typed Purchases prohibit incompatible lifecycle operations
The system SHALL visibly identify a Purchase generated from consignment billing and SHALL prohibit ordinary receiving, full commercial editing, deletion, archival, correction, return, or other operations that would break immutable consignment provenance unless a later explicitly specified workflow authorizes them. Payment creation, payment invalidation, read-only reporting, and balance reconciliation SHALL continue through existing permissioned Purchase behavior.

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

### Requirement: Billing access, audit, and reconciliation are tenant-safe
Billing preparation, conversion, viewing, and reconciliation SHALL enforce dedicated permissions and active-setting boundaries in controllers and domain services. Successful and failed lifecycle decisions SHALL retain actionable audit evidence, and Consignment reconciliation SHALL expose billing readiness, billed Purchase reference, invoice identity, billed amount, paid amount, and outstanding amount without double counting allocation quantities.

#### Scenario: User lacks billing permission
- **WHEN** a user without the relevant consignment billing permission accesses a billing page or conversion action
- **THEN** the page or action SHALL be unavailable or denied

#### Scenario: Billed confirmation is reconciled
- **WHEN** reconciliation displays an approved confirmation linked to a Purchase
- **THEN** it SHALL show the Purchase and supplier invoice references and canonical paid and outstanding balances
- **AND** the underlying approved allocation SHALL be counted exactly once

#### Scenario: Standard Purchase is viewed
- **WHEN** an ordinary Purchase has no consignment-billing source
- **THEN** its existing receiving, payment, correction, and reporting behavior SHALL remain unchanged

### Requirement: Phase 3 excludes post-billing adjustment workflows
Phase 3 SHALL NOT consolidate multiple confirmations into one Purchase, split one confirmation across Purchases, automatically create payments, or implement post-billing supplier returns, debit notes, credit notes, commissions, agreements, ownership conversion, or tax-platform submission.

#### Scenario: Unsupported billing adjustment is attempted
- **WHEN** a request attempts consolidation, splitting, automatic settlement, post-billing return, or supplier credit against consignment billing evidence
- **THEN** the system SHALL reject the operation without changing the confirmation, Purchase, payment, allocation, or inventory state

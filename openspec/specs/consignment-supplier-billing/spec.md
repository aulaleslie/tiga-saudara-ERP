# consignment-supplier-billing Specification

## Purpose
Converts an approved, billing-ready Consignment Billing Confirmation into exactly one supplier Purchase, preserving immutable allocation provenance while letting authorized operators review and adjust the resulting financial terms (price, discount, tax) before the payable is created, without ever mutating physical inventory.
## Requirements
### Requirement: Billing captures authoritative supplier invoice metadata
The system SHALL allow an authorized user to prepare billing metadata and reviewed financial terms only for an approved, billing-ready Consignment Billing Confirmation in the active setting. The metadata SHALL include the supplier invoice/reference number, invoice date, reporting date when applicable, due date or payment term, optional supplier tax reference, notes, and supported attachments, and SHALL be validated against the locked confirmation supplier and setting before conversion. The preview SHALL distinguish immutable approved allocation evidence from the operator's proposed billing terms.

#### Scenario: Valid supplier invoice is prepared
- **WHEN** an authorized user supplies valid invoice metadata and financial terms for an approved billing-ready confirmation
- **THEN** the system SHALL present an exact conversion preview using that confirmation's immutable allocation evidence and the proposed billing calculation
- **AND** no Purchase, payable, payment, or inventory mutation SHALL occur before conversion is confirmed

#### Scenario: Foreign or ineligible confirmation is submitted
- **WHEN** billing metadata targets a foreign-setting, non-approved, non-ready, or already billed confirmation
- **THEN** the system SHALL reject the request without disclosing foreign data or changing any record

### Requirement: Billing operators can review and adjust financial terms before conversion
The system SHALL provide Purchase-style financial controls on the conversion page for each allocation-backed Purchase row: editable unit price, fixed amount per unit or percentage row discount, and editable final row total. The system SHALL provide a fixed amount or percentage global discount and an exact, live payable preview. It SHALL keep product, supplier, source allocation, quantity, and serial identity read-only. A final row total override SHALL become the authoritative row amount and SHALL adjust the displayed unit price consistently with quantity, discount, and tax.

#### Scenario: Fixed discount is entered on each row
- **WHEN** an authorized user enters a fixed Rp10,000 row discount on a 9-unit row and a 3-unit row, each originally priced at Rp4,200,000 per unit, with no tax or global discount
- **THEN** the preview SHALL show a Rp50,280,000 payable and preserve both original Rp4,200,000 source unit prices.

#### Scenario: Percentage row discount and final row total are entered
- **WHEN** an authorized user selects a percentage row discount or overrides a final row total
- **THEN** the preview SHALL recalculate that row, its displayed unit price, and the document total using the same pricing meanings as Purchase create
- **AND** it SHALL reject a discount above 100%, a negative effective price, or a row total that cannot be reconciled.

#### Scenario: Global discount is entered
- **WHEN** an authorized user enters a fixed amount or percentage global discount
- **THEN** the preview SHALL subtract it from the sum of tax-inclusive row totals, leave the individual row tax amounts intact, and show the resulting payable
- **AND** it SHALL reject a global discount greater than that sum.

### Requirement: Billing tax controls follow active-setting PKP policy
For a PKP setting, the system SHALL expose valid line tax choices and a tax-included control defaulted to enabled. It SHALL initialize the tax choice from each allocation's tax snapshot and preserve the original no-edit payable, including currency rounding. For a non-PKP setting, it SHALL hide tax controls, use no line tax, and persist zero tax with `is_tax_included = false`, regardless of submitted tax values.

#### Scenario: New PKP conversion is opened
- **WHEN** an authorized user opens a billing conversion for a PKP setting
- **THEN** the tax-included control SHALL be enabled by default and each row SHALL show its valid initial tax choice and a gross price that reproduces the original payable before edits.

#### Scenario: PKP tax choice or inclusion is changed
- **WHEN** an authorized user changes a valid line tax choice or tax-included state before conversion
- **THEN** the preview and generated Purchase SHALL use the reviewed tax calculation, and the original receipt tax identity and amount SHALL remain traceable.

#### Scenario: Non-PKP conversion receives tax inputs
- **WHEN** a non-PKP conversion request includes a tax ID or tax-included flag
- **THEN** the server SHALL ignore or reject those tax inputs and SHALL create no Purchase tax.

### Requirement: Conversion revalidates financial intent and records the billing decision
The system SHALL use one server-side calculation for preview and conversion, shall rebuild current approved allocation rows under the existing conversion locks, and shall reject missing, duplicate, unknown, or stale row identities and invalid monetary input. It SHALL persist the accepted final controls and totals on Purchase header/details and an auditable original-versus-final billing record without changing source allocation or lineage evidence. A repeated conversion SHALL not alter an already-linked Purchase.

#### Scenario: Preview and conversion agree
- **WHEN** a user submits valid financial terms against unchanged approved evidence
- **THEN** the generated Purchase row amounts, line tax, discounts, document discount, total, and due amount SHALL equal the authoritative server preview.

#### Scenario: Allocation evidence changes after preview
- **WHEN** an allocation-backed row or quantity differs at conversion time
- **THEN** conversion SHALL reject the stale intent and create no Purchase, payment, lineage, or partial billing state.

#### Scenario: A converted request is repeated
- **WHEN** a request is repeated after its confirmation has a linked Purchase
- **THEN** the existing idempotent response SHALL return or reject the linked result without modifying its accepted financial terms.

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
The generated Purchase SHALL preserve quantities, product identity, supplier unit cost, original DPP, original tax identity, and original monetary amounts from approved Phase 2 receipt and serialized allocations backed by Phase 1 receiving snapshots as immutable provenance. Authorized pre-conversion billing adjustments SHALL determine the generated Purchase's reviewed unit price, row discount, selected tax, and row total without rewriting that provenance. Commercially distinct receipt lots SHALL remain distinct Purchase details or retain equivalent lossless lineage, and every generated detail SHALL be durably traceable to the confirmation and contributing allocation evidence.

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

#### Scenario: Reviewed terms differ from receipt evidence
- **WHEN** a user changes price, discount, or tax before conversion
- **THEN** the Purchase SHALL show the reviewed billing terms while lineage and conversion audit SHALL retain the original allocation amounts and identify the difference

### Requirement: Consignment billing is financially active and inventory inert
The generated Purchase SHALL establish the supplier payable using existing Purchase monetary and reference conventions and SHALL be marked physically complete with an explicit consignment-billing source classification. Its payable and tax totals SHALL reflect the authorized reviewed billing terms; an unchanged submission SHALL retain the original allocation-based payable. Conversion SHALL NOT create a Received Note or mutate physical stock, tax/non-tax stock buckets, ProductPrice, average or last purchase cost, serials, Sales, POS, dispatches, returns, or Phase 2 allocation quantities.

#### Scenario: Conversion creates a payable
- **WHEN** a valid confirmation is converted with accepted billing terms
- **THEN** the Purchase total, paid amount, due amount, payment status, supplier, dates, and tax totals SHALL reflect those accepted terms
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

### Requirement: Generated Purchases display grouped consignment source evidence
For a Purchase generated from consignment billing, the detail page SHALL show the consignment receival reference and receiving number for contributing lineage within each existing Purchase product row. It SHALL group lineage by source receival and receiving identity, sum the billed base quantity within each group, and show each billed serial number associated with that group. This presentation SHALL leave Purchase details and immutable lineage records unchanged. It SHALL NOT present internal database IDs as document numbers or a bare `SN` marker as serial evidence.

#### Scenario: Multiple serialized allocations share one source
- **WHEN** several serialized lineage rows on one Purchase detail belong to the same consignment receival and receiving
- **THEN** the detail page SHALL show one source group with their summed billed quantity
- **AND** it SHALL show each associated serial number once within that group

#### Scenario: One Purchase detail spans different sources
- **WHEN** lineage rows on one Purchase detail come from different receivals or receivings
- **THEN** the detail page SHALL show separate groups with their own source numbers, quantities, and serial numbers
- **AND** their displayed quantities SHALL sum to that Purchase detail's billed lineage quantity

#### Scenario: Non-serialized allocation
- **WHEN** a source group has non-serialized lineage quantity
- **THEN** the detail page SHALL include that quantity in the group total
- **AND** it SHALL not imply that a serial number exists for that allocation

#### Scenario: Historical source number is unavailable
- **WHEN** a lineage source document or serial number cannot be resolved
- **THEN** the detail page SHALL display an unavailable indicator for that value while retaining the grouped quantity
- **AND** it SHALL not substitute a database ID as a document or serial number

#### Scenario: Ordinary Purchase is viewed
- **WHEN** a Purchase was not generated from consignment billing
- **THEN** its product rows SHALL retain their existing display without consignment source groups


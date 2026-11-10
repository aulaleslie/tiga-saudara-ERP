# Spec Delta

## MODIFIED Requirements

### Requirement: Source-typed Purchases prohibit incompatible lifecycle operations
The system SHALL visibly identify a Purchase generated from sold-only consignment billing or dedicated consignment buyout and SHALL prohibit ordinary receiving, full commercial editing, deletion, archival, correction, return, or other operations that would break immutable consignment provenance unless a later explicitly specified workflow authorizes them. Payment creation, payment invalidation, read-only reporting, and balance reconciliation SHALL continue through existing permissioned Purchase behavior. Administrative date, note, invoice-reference, and attachment maintenance SHALL follow standard Purchase capabilities as specified below and SHALL NOT be classified as full commercial editing. Buyout Purchases SHALL retain equivalent administrative and payment eligibility and lossless source presentation through their own lineage. Only the dedicated eligible buyout reversal SHALL cancel a finalized buyout liability; ordinary status actions SHALL NOT bypass source protections.

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

#### Scenario: Buyout Purchase is paid
- **WHEN** an authorized user records a partial or full payment against a finalized non-reversed buyout Purchase
- **THEN** existing individual or global Purchase payment rules SHALL update paid and due balances without another stock movement or allocation

#### Scenario: Ordinary lifecycle action targets a buyout
- **WHEN** a user attempts ordinary receiving, commercial editing, deletion, archival, correction, or supplier return on a buyout Purchase
- **THEN** the action SHALL be rejected without changing its commercial, financial, or ownership evidence

#### Scenario: Unauthorized status change is submitted
- **WHEN** an unauthorized user attempts a status change on a consignment-generated Purchase
- **THEN** the system SHALL return HTTP 403 without changing the Purchase or converting the authorization denial into a successful redirect

### Requirement: Phase 3 excludes post-billing adjustment workflows
Legacy sold-only Phase 3 conversion SHALL NOT consolidate multiple confirmations into one Purchase, split one confirmation across Purchases, automatically create payments, or implement post-billing supplier returns, debit notes, credit notes, commissions, agreements, ownership conversion, or tax-platform submission. Dedicated receipt-backed buyout finalization and its constrained full reversal SHALL be provided only through the separately specified buyout workflow; they SHALL NOT extend legacy conversion or permit supplier advances, cross-business acquisition, post-payment supplier returns, or credits.

#### Scenario: Unsupported billing adjustment is attempted
- **WHEN** a request attempts consolidation, splitting, automatic settlement, post-billing return, or supplier credit against consignment billing evidence
- **THEN** the system SHALL reject the operation without changing the confirmation, Purchase, payment, allocation, or inventory state

#### Scenario: Dedicated buyout is finalized
- **WHEN** an approved receipt-backed buyout is finalized through its dedicated workflow
- **THEN** it SHALL follow buyout ownership and stock posting rules without changing the inventory-inert contract of legacy confirmation conversion

#### Scenario: Existing sold-only conversion is repeated after deployment
- **WHEN** a historical sold-only confirmation is validated or converted after buyout support is deployed
- **THEN** its snapshot contract, single-Purchase linkage, pricing semantics, and inventory-inert behavior SHALL remain compatible

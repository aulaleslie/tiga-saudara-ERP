# Spec Delta

## MODIFIED Requirements

### Requirement: Source-typed Purchases prohibit incompatible lifecycle operations
The system SHALL visibly identify a Purchase generated from sold-only consignment billing or dedicated consignment buyout and SHALL prohibit ordinary receiving, full commercial editing, deletion, archival, correction, return, or other operations that would break immutable consignment provenance unless a later explicitly specified workflow authorizes them. Payment creation, payment invalidation, read-only reporting, and balance reconciliation SHALL continue through existing permissioned Purchase behavior. The existing standard monetary editor SHALL be an explicit exception for eligible consignment-billing and buyout Purchases as specified below, without enabling full editing or cost correction. Administrative date, note, invoice-reference, and attachment maintenance SHALL follow standard Purchase capabilities as specified below and SHALL NOT be classified as full commercial editing. Buyout Purchases SHALL retain equivalent administrative and payment eligibility and lossless source presentation through their own lineage. Only the dedicated eligible buyout reversal SHALL cancel a finalized buyout liability; ordinary status actions SHALL NOT bypass source protections.

#### Scenario: User attempts ordinary receiving
- **WHEN** a user invokes a Purchase receiving action for a consignment-billing Purchase
- **THEN** the system SHALL reject it without creating a Received Note or changing inventory

#### Scenario: User attempts provenance-breaking edit
- **WHEN** a user attempts to change supplier, product, quantity, source identity, or inventory-cost evidence outside the authorized monetary editor on a generated consignment Purchase
- **THEN** the system SHALL reject the change without mutating the Purchase or its lineage

#### Scenario: Authorized payment is recorded
- **WHEN** an authorized user pays all or part of an eligible consignment-billing Purchase
- **THEN** the existing Purchase payment workflow SHALL update active payment evidence and live outstanding balance
- **AND** no Consignment allocation or inventory evidence SHALL change

#### Scenario: Buyout Purchase is paid
- **WHEN** an authorized user records a partial or full payment against a finalized non-reversed buyout Purchase
- **THEN** existing individual or global Purchase payment rules SHALL update paid and due balances without another stock movement or allocation

#### Scenario: Ordinary lifecycle action targets a buyout
- **WHEN** a user attempts ordinary receiving, full commercial editing, deletion, archival, correction, or supplier return on a buyout Purchase
- **THEN** the action SHALL be rejected without changing its commercial, financial, or ownership evidence

#### Scenario: Unauthorized status change is submitted
- **WHEN** an unauthorized user attempts a status change on a consignment-generated Purchase
- **THEN** the system SHALL return HTTP 403 without changing the Purchase or converting the authorization denial into a successful redirect

#### Scenario: Authorized monetary edit uses the explicit exception
- **WHEN** an eligible consignment-billing or buyout Purchase is opened or saved through the standard monetary editor
- **THEN** allowed financial fields SHALL be editable under the standard Purchase permission and status rules
- **AND** the other source lifecycle restrictions SHALL remain enforced


### Requirement: Administrative parity preserves commercial provenance
Administrative maintenance SHALL be available on existing and future consignment-generated Purchases in normal Purchase views and applicable Global Purchase Payments views wherever standard Purchases provide the same action. Informational copy SHALL distinguish editable administrative information from locked commercial details. These actions SHALL preserve supplier identity, product rows, quantities, costs, discounts, tax treatment, totals, source identity, allocation snapshots, conversion audit, inventory, serials, and payment evidence. Ordinary receiving, receiving cancellation, commercial correction, returns, deletion, and archival SHALL remain prohibited for consignment-billing Purchases. Authorized monetary editing SHALL be a separate explicit exception and SHALL preserve original source evidence.

#### Scenario: Existing converted Purchase is maintained
- **WHEN** an authorized user opens a previously converted Purchase through a supported normal or global screen
- **THEN** the same administrative capabilities as an equivalent standard Purchase SHALL be available without reconversion or historical data rewriting
- **AND** the interface SHALL distinguish protected execution and source fields from supported administrative and monetary actions

#### Scenario: Protected commercial change accompanies metadata
- **WHEN** a request combines an allowed administrative edit with a prohibited commercial or source change
- **THEN** the system SHALL reject the prohibited mutation without partially persisting the combined change

#### Scenario: Administrative changes preserve operational facts
- **WHEN** any supported administrative action succeeds
- **THEN** commercial values, consignment source evidence, inventory, serials, and payment records SHALL remain unchanged
- **AND** standard permissioned payment creation, invalidation, and reconciliation SHALL remain available separately

## ADDED Requirements

### Requirement: Generated Purchases support standard monetary editing
Existing and future consignment-billing and buyout Purchases SHALL support the standard Purchase monetary editor under its status, normal/global permissions, tenant and archive rules. It SHALL update supported prices, discounts, invoice tax and charges on existing header/detail records, retain active payments and reconcile paid/due balances. A total below active paid value SHALL be rejected.

#### Scenario: Existing generated Purchase is repriced
- **WHEN** an authorized user edits financial values on an eligible generated Purchase with existing source-backed rows
- **THEN** amounts SHALL update on the same Purchase and detail identities using the standard monetary calculation
- **AND** historical rows SHALL not be merged, removed or automatically normalized to the new buyout grouping

#### Scenario: Partly or fully paid Purchase is edited
- **WHEN** accepted monetary inputs produce a total at least the active paid amount
- **THEN** standard paid, due and payment-status reconciliation SHALL apply without creating, deleting or invalidating payment records
- **AND** a total below paid value SHALL fail atomically

#### Scenario: Monetary edit attempts protected changes
- **WHEN** a request changes product, quantity, row membership, supplier, source identity, serials, locations or other fields protected by standard monetary mode
- **THEN** it SHALL be rejected without changing the Purchase or its source evidence

#### Scenario: Permission or business boundary fails
- **WHEN** a user lacks standard Purchase monetary authority or violates the normal/global business-access boundary
- **THEN** opening and saving SHALL be denied even if the user has buyout or consignment conversion permissions

### Requirement: Monetary maintenance preserves acquisition and source evidence
Monetary editing SHALL NOT change stock, physical tax buckets, serials, average/last purchase cost, acquisition cost events, historical sales HPP, source snapshots, allocation capacity or accepted conversion evidence. Current Purchase financial values SHALL remain distinct from original source values. This exception SHALL NOT grant full correction, supplier return, cancellation, deletion, archival or reversal capabilities.

#### Scenario: Invoice price or tax changes after acquisition
- **WHEN** a generated Purchase monetary edit changes price, discount or invoice tax
- **THEN** current Purchase totals SHALL change while original conversion evidence, stock, cost and source documents SHALL remain unchanged

#### Scenario: Source view or conversion retry follows a monetary edit
- **WHEN** the linked source is viewed or its already-completed conversion/finalization is retried
- **THEN** it SHALL retain original accepted evidence and its existing Purchase identity without rewriting that evidence or generating another payable or posting
- **AND** an authorized difference in current Purchase financial values SHALL not itself imply corrupt source evidence

#### Scenario: Other Purchase and source actions are invoked
- **WHEN** correction, returns, deletion, cancellation, archival, receiving or buyout reversal is requested
- **THEN** the existing eligibility and source restrictions for that operation SHALL continue to apply independently of monetary-edit eligibility

# Spec Delta

## Purpose

Provide an auditable view of product quantities owed between businesses and automatically rebalance those balances from completed Stock Transfer V3 receipts without a separate return workflow.

## ADDED Requirements

### Requirement: Completed cross-business receipts create debt effects
The system SHALL record an immutable debt effect for each successfully completed workflow version `3` receipt allocation whose source and destination businesses differ. The debt key SHALL consist of the two business identities, product identity, and stock condition; same-business allocations and workflow versions `1` and `2` SHALL have no effect on this ledger.

#### Scenario: Cross-business receipt completes
- **WHEN** a version `3` receipt allocation moves a good-condition product from Business A to Business B and the receipt completes
- **THEN** the ledger records that Business B owes Business A the received quantity of that product in good condition

#### Scenario: Same-business receipt completes
- **WHEN** a version `3` receipt allocation has the same source and destination business
- **THEN** the system completes inventory receipt without creating a debt effect

#### Scenario: Broken stock crosses businesses
- **WHEN** a cross-business version `3` receipt allocation contains broken-condition stock
- **THEN** the system records it under a broken-condition balance separate from the same product's good-condition balance without prohibiting the transfer

#### Scenario: Legacy transfer completes
- **WHEN** a workflow version `1` or `2` transfer is viewed or processed
- **THEN** the new debt ledger neither derives nor changes a balance from that transfer and preserves its existing return behavior

### Requirement: Reverse receipts rebalance only matching debt
For the same pair of businesses, product, and stock condition, a completed receipt in the opposite direction SHALL reduce the existing directional debt before any excess creates debt in the reverse direction. Different products or conditions MUST NOT offset one another.

#### Scenario: Reverse receipt partially settles debt
- **WHEN** Business B owes Business A ten good units of Product X and A receives four good units of Product X from B
- **THEN** the resulting balance states that B owes A six good units of Product X

#### Scenario: Reverse receipt exceeds debt
- **WHEN** Business B owes Business A ten good units of Product X and A receives twelve good units of Product X from B
- **THEN** the prior debt is fully settled and the resulting balance states that A owes B two good units of Product X

#### Scenario: Different product does not settle debt
- **WHEN** Business B owes Business A Product X and A receives Product Y from B
- **THEN** the Product X balance remains unchanged and the Product Y receipt creates or rebalances its own balance

#### Scenario: Different condition does not settle debt
- **WHEN** Business B owes Business A good Product X and A receives broken Product X from B
- **THEN** the good balance remains unchanged and the broken receipt creates or rebalances a separate broken balance

#### Scenario: Serialized product is rebalanced
- **WHEN** the received allocation contains valid serial-tracked units of the same product and condition
- **THEN** its quantity rebalances product debt without requiring the serials originally received from the opposite business

### Requirement: Debt changes are atomic, idempotent, and auditable
Inventory receipt, transfer completion, and every corresponding debt effect MUST commit in one authoritative transaction. Each receipt allocation SHALL affect the ledger at most once and SHALL retain enough provenance to trace the effect to its transfer, receipt movement, allocation, businesses, product, condition, quantity, and receipt time.

#### Scenario: Receipt is retried
- **WHEN** a committed version `3` receipt request is repeated
- **THEN** inventory and debt balances remain unchanged and no duplicate debt effect is recorded

#### Scenario: Debt recording fails
- **WHEN** any required debt event or balance update cannot be persisted during receipt
- **THEN** the entire receipt rolls back, including inventory, serial, custody, transaction, history, debt, and transfer-status effects

#### Scenario: Concurrent receipts affect one balance
- **WHEN** two receipts concurrently affect the same business-pair, product, and condition balance
- **THEN** authoritative locking serializes their debt effects and the final balance includes each committed allocation exactly once

#### Scenario: User reviews balance provenance
- **WHEN** an authorized user opens a balance drill-down
- **THEN** every balance-changing event identifies its direction, quantity, transfer document, and completed receipt evidence

### Requirement: Dispatched quantities remain projections until receipt
The system SHALL keep authoritative debt based only on completed cross-business receipts. Version `3` dispatch allocations whose transfer remains dispatched and which have no settling receipt or cancellation allocation MAY be shown separately as in-transit and projected effects for a product already listed because it has authoritative debt, but MUST NOT settle or create authoritative debt before receipt. A product with only in-transit activity and no authoritative debt MUST NOT appear in the dashboard.

#### Scenario: Reverse transfer is dispatched
- **WHEN** a transfer that would reduce an existing debt is dispatched but not received
- **THEN** the outstanding balance is unchanged while the listed product may show the potential reduction separately

#### Scenario: Dispatched transfer is cancelled
- **WHEN** an in-transit transfer is cancelled before receipt
- **THEN** it disappears from the projection and produces no debt event or authoritative balance change

#### Scenario: Balance changes before projected receipt
- **WHEN** another receipt changes the balance while a transfer remains in transit
- **THEN** the dashboard recomputes the projection from the current authoritative balance and immutable in-transit allocations

### Requirement: Authorized users can inspect cross-business debt conditions
The Stock Transfer navigation SHALL provide a product-by-business debt matrix to users holding `stockTransfers.view-business-debt` in the active business. Consistent with V3 document discovery, that permission SHALL expose all cross-business balances without requiring membership in either participating business. The dashboard SHALL present product identity, collapsed debtor-business totals, expandable creditor-business breakdowns, applicable in-transit projection, and drill-down provenance including stock condition, without granting transfer creation, approval, receipt, cancellation, history, route-configuration, or protected stock-bucket authority.

#### Scenario: Authorized user opens dashboard
- **WHEN** a user holds the dedicated debt-balance permission in the active business
- **THEN** the user can view directional balances and their permitted provenance through the Stock Transfer menu

#### Scenario: User lacks balance permission
- **WHEN** a user directly requests the dashboard or its data without the dedicated permission
- **THEN** the system denies access and emits no balance payload

#### Scenario: Balance access does not grant transfer actions
- **WHEN** a balance viewer lacks an existing Stock Transfer action permission
- **THEN** the dashboard does not grant or expose the corresponding create, approval, receipt, cancellation, route, or history action

#### Scenario: Dashboard shows zero state
- **WHEN** no nonzero authoritative balance or qualifying in-transit allocation exists
- **THEN** the dashboard presents a clear empty or zero-debt state without inventing legacy obligations

### Requirement: Debt dashboard uses one product row and expandable debtor columns
The dashboard SHALL render exactly one row for each product having at least one nonzero authoritative cross-business debt. Each business SHALL have one collapsed debtor column whose value is the total quantity that business owes all other businesses for the product, summed across creditor businesses and stock conditions. A business that owes none SHALL display `0`. Expanding a debtor-business column SHALL replace its total with one creditor subcolumn per other business, showing how much the expanded business owes that creditor; the expanded values MUST sum to the collapsed total.

#### Scenario: Product has debt in several businesses
- **WHEN** Business B owes six units of Product X to Business A and four units to Business C
- **THEN** Product X appears once, collapsed Business B displays ten, and expanding Business B displays six under Business A and four under Business C

#### Scenario: Business has no debt for listed product
- **WHEN** Product X is listed because another business owes it but Business A owes none
- **THEN** the collapsed Business A cell displays `0`

#### Scenario: Product has good and broken debt
- **WHEN** one debtor owes the same creditor eight good and two broken units of Product X
- **THEN** its matrix value displays ten while drill-down provenance preserves the separate good and broken balances

#### Scenario: Product has no authoritative debt
- **WHEN** every business-pair-and-condition balance for Product X is zero, even if Product X has an in-transit allocation
- **THEN** Product X does not appear in the dashboard

#### Scenario: Expand and collapse business column
- **WHEN** a user expands or collapses a business header
- **THEN** the table follows the existing Stok Lintas Bisnis column expansion interaction while keeping the product row fixed

### Requirement: Existing V3 receipt evidence can rehydrate the ledger
The system SHALL provide an idempotent, deterministic rehydration operation that processes completed cross-business workflow version `3` receipt allocations in stable receipt order, creates any missing debt events once, and leaves already-recorded events unchanged.

#### Scenario: Current production-equivalent data is rehydrated
- **WHEN** rehydration runs against the current dataset containing no cross-business version `3` receipt allocations
- **THEN** it completes successfully and creates no balance

#### Scenario: Missing historical event is rehydrated
- **WHEN** a completed cross-business version `3` receipt allocation has no corresponding debt event
- **THEN** rehydration records its effect using immutable receipt evidence

#### Scenario: Rehydration is repeated
- **WHEN** the operation runs again after all eligible allocations have events
- **THEN** it creates no duplicates and leaves balances unchanged

### Requirement: Users can start an ordinary V3 transfer from selected debt products
Each listed product row SHALL have one selection checkbox independent of its business cells. A user who also holds `stockTransfers.create` SHALL be able to select any combination of listed products and open the existing workflow version `3` Stock Transfer create form with each chosen product present at quantity zero. The launch SHALL carry no debtor, creditor, debt quantity, stock condition, route, or balance authority and SHALL create no transfer, debt event, reservation, or inventory effect until the existing Stock Transfer workflow performs its normal actions.

#### Scenario: Select products across business debts
- **WHEN** an authorized user selects several listed product rows regardless of which businesses owe them and chooses Buat Transfer Stok
- **THEN** the existing V3 create form opens with those distinct products preselected at zero quantity and retains its ordinary condition behavior

#### Scenario: Enter non-serialized return quantities
- **WHEN** the create form contains a preselected non-serialized product at zero quantity
- **THEN** the user can enter its intended positive whole quantity using the existing Stock Transfer controls and validation

#### Scenario: Scan serials for a preselected serialized product
- **WHEN** the create form contains a preselected serialized product at zero quantity
- **THEN** the user can scan or select serials through the existing V3 entry flow and quantity continues to derive from the distinct valid serial selection

#### Scenario: Balance viewer lacks create permission
- **WHEN** a user can view debt balances but lacks `stockTransfers.create`
- **THEN** the dashboard does not offer the create-transfer action and a direct launch request is denied

#### Scenario: Prefill payload is forged or stale
- **WHEN** a launch request contains an unauthorized, inactive, non-stock-managed, duplicate, malformed, or no-longer-visible product identity
- **THEN** the server rejects the entire prefill launch, opens no prefilled rows, provides actionable feedback, and creates no transfer

#### Scenario: Transfer is routed differently during approval
- **WHEN** the eventual approved allocations do not settle any debt visible on the source dashboard row
- **THEN** debt rebalancing follows the actual immutable receipt allocations rather than the dashboard launch context

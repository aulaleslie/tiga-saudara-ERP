## ADDED Requirements

### Requirement: Supplier detail global purchase-payment workspace
The system SHALL display an additional full Pembayaran Pembelian Global workspace beneath an authorized supplier detail page, SHALL constrain all workspace data to the displayed supplier across all businesses, and SHALL leave the standalone Pembayaran Pembelian Global page available and unchanged.

#### Scenario: Authorized user sees the supplier workspace
- **WHEN** a user with `suppliers.show` and `purchasePayments.global.access` opens a supplier detail page
- **THEN** the page displays the global purchase-payment summary cards, business and date filters, search, sorting, pagination, global detail and history actions, and any payment action authorized by existing permissions
- **AND** the existing supplier detail information and purchase content remain available

#### Scenario: Workspace is hidden without global payment access
- **WHEN** a user with `suppliers.show` but without `purchasePayments.global.access` opens a supplier detail page
- **THEN** the supplier detail remains accessible
- **AND** the embedded Pembayaran Pembelian Global workspace is not displayed

#### Scenario: Read-only permission remains read-only
- **WHEN** a user has `suppliers.show` and `purchasePayments.global.access` but does not have `purchasePayments.create`
- **THEN** the embedded workspace permits the same global read-only list, detail, and history access as the standalone workspace
- **AND** it does not expose or permit payment creation

### Requirement: Supplier-constrained global purchase results and summaries
The embedded supplier workspace MUST apply the displayed supplier's immutable identifier to every eligible-purchase row and every summary calculation while composing that constraint with all existing global business, date, search, status-card, sorting, and pagination behavior.

#### Scenario: Empty business filter includes all businesses for one supplier
- **WHEN** an authorized user opens the embedded workspace without selecting a business
- **THEN** eligible purchases from every business are included only when their `supplier_id` matches the displayed supplier
- **AND** no purchase belonging to another supplier appears in the table or summary cards

#### Scenario: Selected businesses narrow one supplier's results
- **WHEN** an authorized user applies one or more business filters in the embedded workspace
- **THEN** the table and every summary card include only the displayed supplier's eligible purchases from the selected businesses
- **AND** the supplier constraint remains active through filter changes, card selection, searching, sorting, pagination, and page refresh

#### Scenario: Recent-payment summary remains supplier constrained
- **WHEN** the embedded workspace calculates recent global purchase payments
- **THEN** only active payments related to eligible purchases for the displayed supplier and applied businesses contribute to its count and total

#### Scenario: Client cannot change the supplier constraint
- **WHEN** a client attempts to mutate the embedded Livewire workspace's supplier identifier
- **THEN** the mutation is rejected or ignored
- **AND** data for another supplier is not returned

### Requirement: Embedded supplier payment workflow parity
The embedded supplier workspace SHALL use the existing global purchase-payment eligibility, global detail/history routes, candidate selection, atomic allocation service, and permission checks.

#### Scenario: Payment action uses existing global workflow
- **WHEN** an authorized user starts payment from an eligible purchase in the embedded supplier workspace
- **THEN** the existing global purchase multi-invoice form offers only eligible purchases for that same supplier
- **AND** submission uses the existing global purchase-payment service and validation rules

#### Scenario: Standalone workspace remains global
- **WHEN** an authorized user opens the standalone Pembayaran Pembelian Global page after this change
- **THEN** it continues to show eligible purchases across suppliers and businesses according to its existing filters and permissions

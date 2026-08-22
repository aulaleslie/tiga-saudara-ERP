## ADDED Requirements

### Requirement: Customer detail global sales-payment workspace
The system SHALL display an additional full Pembayaran Penjualan Global workspace beneath an authorized customer detail page, SHALL constrain all workspace data to the displayed customer across all businesses, and SHALL leave the standalone Pembayaran Penjualan Global page available and unchanged.

#### Scenario: Authorized user sees the customer workspace
- **WHEN** a user with `customers.show` and `salePayments.global.access` opens a customer detail page
- **THEN** the page displays the global sales-payment summary cards, business and date filters, search, sorting, pagination, global detail and history actions, and any payment action authorized by existing permissions
- **AND** the existing customer detail information remains available

#### Scenario: Workspace is hidden without global payment access
- **WHEN** a user with `customers.show` but without `salePayments.global.access` opens a customer detail page
- **THEN** the customer detail remains accessible
- **AND** the embedded Pembayaran Penjualan Global workspace is not displayed

#### Scenario: Read-only permission remains read-only
- **WHEN** a user has `customers.show` and `salePayments.global.access` but does not have `salePayments.create`
- **THEN** the embedded workspace permits the same global read-only list, detail, and history access as the standalone workspace
- **AND** it does not expose or permit payment creation

### Requirement: Customer-constrained global sales results and summaries
The embedded customer workspace MUST apply the displayed customer's immutable identifier to every eligible-sale row and every summary calculation while composing that constraint with all existing global business, date, search, status-card, sorting, and pagination behavior.

#### Scenario: Empty business filter includes all businesses for one customer
- **WHEN** an authorized user opens the embedded workspace without selecting a business
- **THEN** eligible sales from every business are included only when their `customer_id` matches the displayed customer
- **AND** no sale belonging to another customer appears in the table or summary cards

#### Scenario: Selected businesses narrow one customer's results
- **WHEN** an authorized user applies one or more business filters in the embedded workspace
- **THEN** the table and every summary card include only the displayed customer's eligible sales from the selected businesses
- **AND** the customer constraint remains active through filter changes, card selection, searching, sorting, pagination, and page refresh

#### Scenario: Recent-payment summary remains customer constrained
- **WHEN** the embedded workspace calculates recent global sales payments
- **THEN** only active payments related to eligible sales for the displayed customer and applied businesses contribute to its count and total

#### Scenario: Client cannot change the customer constraint
- **WHEN** a client attempts to mutate the embedded Livewire workspace's customer identifier
- **THEN** the mutation is rejected or ignored
- **AND** data for another customer is not returned

### Requirement: Embedded customer payment workflow parity
The embedded customer workspace SHALL use the existing global sales-payment eligibility, global detail/history routes, candidate selection, monetary-payment-only rules, atomic allocation service, and permission checks.

#### Scenario: Payment action uses existing global workflow
- **WHEN** an authorized user starts payment from an eligible sale in the embedded customer workspace
- **THEN** the existing global sales multi-invoice form offers only eligible sales for that same customer
- **AND** submission uses the existing global sales-payment service and validation rules

#### Scenario: Standalone workspace remains global
- **WHEN** an authorized user opens the standalone Pembayaran Penjualan Global page after this change
- **THEN** it continues to show eligible sales across customers and businesses according to its existing filters and permissions

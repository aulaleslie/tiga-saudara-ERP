## Purpose

Enable privileged users to edit monetary values in fulfilled purchase and sale documents with granular authorization, while preserving execution facts and maintaining separate correction workflows.

## Requirements

### Requirement: Approved unfulfilled documents remain fully editable with explicit authority
The system SHALL allow an approved Purchase that has not been received and an approved Sale that has not been dispatched to use the existing full edit behavior, including quantity changes, only when the user has ordinary edit authority and the corresponding approved-document permission.

#### Scenario: Authorized approved purchase can change quantity before receiving
- **WHEN** a user with `purchases.update` and `purchases.approved.edit` edits an `APPROVED` Purchase with no receipt execution
- **THEN** the system SHALL allow the existing full document edit behavior, including quantity changes

#### Scenario: Authorized approved sale can change quantity before dispatch
- **WHEN** a user with `sales.edit` and `sales.approved.edit` edits an `APPROVED` Sale with no dispatch execution
- **THEN** the system SHALL allow the existing full document edit behavior, including quantity changes

### Requirement: Privileged users can edit fulfilled document monetary values in place
The system SHALL allow a user with ordinary edit authority plus `purchases.received.monetary.edit` to edit a Purchase in `RECEIVED` or `RECEIVED PARTIALLY` status, and a user with ordinary edit authority plus `sales.dispatched.monetary.edit` to edit a Sale in `DISPATCHED` or `DISPATCHED PARTIALLY` status, within the active setting. The edit SHALL persist supported monetary values directly to the existing document header and detail rows.

#### Scenario: Authorized user changes a received purchase line price
- **WHEN** an authorized user saves a monetary edit for an eligible received Purchase
- **THEN** the system SHALL update the allowed monetary values on the existing Purchase and PurchaseDetail records
- **AND** the system SHALL retain every existing PurchaseDetail primary key

#### Scenario: Authorized user changes a dispatched sale monetary value
- **WHEN** an authorized user saves a monetary edit for an eligible dispatched Sale
- **THEN** the system SHALL update the allowed monetary values on the existing Sale and SaleDetails records
- **AND** the system SHALL retain every existing SaleDetails primary key

#### Scenario: Unauthorized user is denied
- **WHEN** a user lacks either ordinary edit authority or the applicable post-fulfillment monetary permission and attempts to open or save an eligible document
- **THEN** the system SHALL deny the action at the backend
- **AND** the system SHALL not change the document

### Requirement: Fulfilled document monetary edits preserve execution facts
For post-fulfillment monetary editing, the system SHALL prohibit changes to quantity, product identity, row membership, bundle composition, counterparty, document date, reference, payment method, business/setting, receipt or dispatch records, locations, serials, and stock movements. The UI SHALL not offer those changes, and the backend SHALL reject altered values or a non-one-to-one line mapping.

#### Scenario: Quantity cannot be changed after receiving or dispatch
- **WHEN** an authorized user opens a received or dispatched document in monetary-only mode
- **THEN** quantity, add-row, remove-row, product, and bundle controls SHALL be unavailable
- **AND** a submission that changes any protected quantity or line membership SHALL be rejected

#### Scenario: Existing receipt and dispatch links survive monetary edit
- **WHEN** a post-fulfillment monetary edit is successfully saved
- **THEN** existing receipt-note detail, dispatch-detail, bundle, serial, and stock-history links SHALL remain attached to their original document detail rows

### Requirement: Fulfilled document monetary edits do not change price or cost snapshots
Saving a post-fulfillment monetary edit SHALL not create or update product stock, `last_purchase_price`, `average_purchase_price`, purchase-cost replay data, or SaleDetails cost snapshot values. The system SHALL not invoke receiving, dispatch, product-price, historical-replay, purchase-cost-recalculation, or sales-cost-snapshot services as part of this edit.

#### Scenario: Late invoice price adjustment leaves product prices and HPP untouched
- **WHEN** an authorized user changes a received Purchase or dispatched Sale monetary value
- **THEN** product price records and existing SaleDetails cost snapshot values SHALL retain their values from before the edit

### Requirement: Existing received-purchase correction behavior remains separate
The system SHALL retain the existing `purchases.received.correct` workflow, correction audit, payment reconciliation, and explicit optional cost-recalculation behavior without changing its authorization, route, data model, or outcomes.

#### Scenario: Existing correction workflow remains available
- **WHEN** a user with `purchases.received.correct` opens an eligible received Purchase correction action
- **THEN** the system SHALL provide the existing correction workflow independently of the new monetary-only edit permission

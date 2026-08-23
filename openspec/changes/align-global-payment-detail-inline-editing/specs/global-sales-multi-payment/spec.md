## MODIFIED Requirements

### Requirement: Dedicated cross-setting read-only sale inspection
The system SHALL provide dedicated global detail and payment-history contexts that match the normal sale detail presentation, permit only defined inline metadata updates under existing edit authorization, and do not apply the active session setting restriction.

#### Scenario: User opens a sale belonging to another setting
- **WHEN** an authorized user opens a base-eligible paid or payable sale whose setting differs from the active session setting
- **THEN** the global detail displays the sale and related dispatch, attachment, and payment information using the normal sale detail presentation
- **AND** the page presents company and validation context from the sale's actual setting

#### Scenario: Authorized user edits inline sale metadata globally
- **WHEN** a user has `salePayments.global.access` and `sales.edit` and views a non-archived sale through global detail
- **THEN** the existing inline editors for tax invoice number and sale note are available
- **AND** a saved value updates the viewed sale even when its setting differs from the active session setting

#### Scenario: Global access alone does not grant inline editing
- **WHEN** a user has `salePayments.global.access` but lacks `sales.edit`, or the viewed sale is archived
- **THEN** tax invoice number and sale note remain visible but read-only
- **AND** a direct or tampered inline update request is forbidden

#### Scenario: Unrelated sale mutations remain unavailable
- **WHEN** an authorized user views a sale through the global detail route
- **THEN** full update, approval, dispatch, archive, delete, duplication, and attachment-management controls are absent
- **AND** payment creation is available only when the sale has a positive current live outstanding balance and the user has `salePayments.create`
- **AND** a fully paid sale remains inspectable but cannot open or submit a new payment

#### Scenario: Global detail payment action opens multi-payment form
- **WHEN** an authorized user selects the payment action from a payable global sale detail
- **THEN** the system redirects to the same customer multi-payment page used by the global list action
- **AND** the viewed sale becomes the starting sale

#### Scenario: Normal sale routes remain setting-scoped
- **WHEN** a user requests an existing normal sale-detail or inline-edit context for a sale outside the active setting
- **THEN** the normal ownership behavior remains in force
- **AND** global payment access is granted only through dedicated routes protected by `salePayments.global.access`

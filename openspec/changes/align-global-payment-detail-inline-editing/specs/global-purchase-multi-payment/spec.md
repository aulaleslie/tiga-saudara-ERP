## MODIFIED Requirements

### Requirement: Dedicated cross-setting read-only purchase detail
The system SHALL provide a dedicated global purchase-detail context that matches the normal purchase detail presentation, permits only defined inline metadata updates under existing edit authorization, and does not apply the active session setting restriction, while leaving the normal setting-scoped purchase-detail route unchanged.

#### Scenario: User opens a purchase belonging to another setting
- **WHEN** an authorized user selects a base-eligible paid or payable purchase whose `setting_id` differs from the active session setting
- **THEN** the dedicated global detail route displays that purchase, its receiving information, attachments, and payment history using the normal purchase detail presentation
- **AND** company and validation context come from the purchase's actual setting
- **AND** the request is not rejected solely because the purchase belongs to another setting

#### Scenario: Authorized user edits inline purchase metadata globally
- **WHEN** a user has `purchasePayments.global.access` and `purchases.update` and views a non-archived purchase through global detail
- **THEN** the existing inline editors for supplier purchase number, tax invoice number, and purchase note are available
- **AND** a saved value updates the viewed purchase even when its setting differs from the active session setting
- **AND** business-scoped uniqueness validation uses the viewed purchase's `setting_id`

#### Scenario: Global access alone does not grant inline editing
- **WHEN** a user has `purchasePayments.global.access` but lacks `purchases.update`, or the viewed purchase is archived
- **THEN** supplier purchase number, tax invoice number, and purchase note remain visible but read-only
- **AND** a direct or tampered inline update request is forbidden

#### Scenario: Unrelated purchase mutations remain unavailable
- **WHEN** an authorized user views a purchase through the global detail context
- **THEN** full update, delete, approval, receiving, duplication, archive, and attachment-management controls are absent
- **AND** payment creation is available only when the purchase has a positive current live outstanding balance and the user has `purchasePayments.create`
- **AND** a fully paid purchase remains inspectable but cannot open or submit a new payment

#### Scenario: Global detail payment action opens multi-payment form
- **WHEN** an authorized user selects `Tambah Pembayaran` from a payable global purchase detail
- **THEN** the system redirects to the same supplier multi-payment page used by the global list action
- **AND** the viewed purchase becomes the starting purchase

#### Scenario: Normal purchase routes remain setting-scoped
- **WHEN** a user requests an existing normal purchase-detail or inline-edit context for a purchase outside the active setting
- **THEN** the existing setting ownership guard continues to reject the request
- **AND** global access is granted only through the dedicated authorized global context


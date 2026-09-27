## MODIFIED Requirements

### Requirement: Dedicated cross-setting read-only purchase detail
The system SHALL provide a dedicated global purchase-detail context that matches the normal purchase detail presentation, permits defined inline metadata updates and narrowly authorized monetary/date adjustments, and does not apply the active session setting restriction, while leaving normal setting-scoped purchase routes unchanged.

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

#### Scenario: Authorized user opens monetary adjustment globally
- **WHEN** a user has `purchasePayments.global.access`, `purchases.update`, and `purchases.received.monetary.edit` and views an eligible received or partially received purchase
- **THEN** global detail displays `Ubah Nilai (Moneter)`
- **AND** the dedicated global entry route opens the existing monetary-only purchase form using the purchase's actual setting context
- **AND** no full-edit controls become available

#### Scenario: Authorized user opens purchase date adjustment globally
- **WHEN** a user has `purchasePayments.global.access` and at least one applicable purchase reporting-date or due-date override permission for an eligible purchase
- **THEN** global detail displays the combined date-adjustment process
- **AND** it exposes only the date fields the user is authorized to change
- **AND** the dedicated global endpoint can save an authorized adjustment when the purchase belongs to another setting

#### Scenario: Global access alone does not grant transaction adjustment
- **WHEN** a user has `purchasePayments.global.access` but lacks an applicable inline, monetary, reporting-date, or due-date permission
- **THEN** the corresponding controls remain read-only or absent
- **AND** direct or tampered global adjustment requests are forbidden

#### Scenario: Unrelated purchase mutations remain unavailable
- **WHEN** an authorized user views a purchase through the global detail context
- **THEN** full update, delete, approval, receiving, duplication, archive, attachment-management, and received-correction controls are absent
- **AND** payment creation is available only when the purchase has a positive current live outstanding balance and the user has `purchasePayments.create`
- **AND** a fully paid purchase remains inspectable but cannot open or submit a new payment

#### Scenario: Global detail payment action opens multi-payment form
- **WHEN** an authorized user selects `Tambah Pembayaran` from a payable global purchase detail
- **THEN** the system redirects to the same supplier multi-payment page used by the global list action
- **AND** the viewed purchase becomes the starting purchase

#### Scenario: Normal purchase routes remain setting-scoped
- **WHEN** a user requests an existing normal purchase-detail, edit, date-adjustment, or inline-edit context for a purchase outside the active setting
- **THEN** the existing setting ownership guard continues to reject the request
- **AND** cross-setting access is granted only through dedicated authorized global routes

#### Scenario: Adjustment changes the current global result membership
- **WHEN** an authorized global monetary adjustment succeeds and the purchase no longer qualifies for its current global detail or selected payment view
- **THEN** the system preserves the committed adjustment
- **AND** it redirects to a safe Global Purchase Payment destination with success feedback instead of returning an authorization or not-found failure

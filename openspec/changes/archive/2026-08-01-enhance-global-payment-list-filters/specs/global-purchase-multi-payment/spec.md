## MODIFIED Requirements

### Requirement: Payment-focused global purchase list
The system SHALL provide `Pembayaran Pembelian Global` using the same columns, search, sorting, pagination, status presentation, payment presentation, and general table layout as the operational `Semua Pembelian` list while querying eligible purchases across all settings.

#### Scenario: List is not scoped to the active setting
- **WHEN** an authorized user opens `Pembayaran Pembelian Global` while any setting is active in the session
- **THEN** the list can contain eligible purchases from every `setting_id`
- **AND** changing the active session setting does not restrict the list to that setting

#### Scenario: Fully received paid and payable purchases are listed
- **WHEN** the global payment list is queried without a selected summary card
- **THEN** it contains non-archived purchases whose exact status is `RECEIVED`
- **AND** both purchases with a positive current live outstanding balance and fully paid purchases with a live outstanding balance less than or equal to zero are listed
- **AND** purchases in `APPROVED`, `RECEIVED PARTIALLY`, drafted, waiting-approval, rejected, returned, or archived states are excluded

#### Scenario: Business and document date filters compose with the list
- **WHEN** an authorized user selects a business and/or an inclusive document-date range
- **THEN** the list contains only eligible purchases with the selected `setting_id` and whose purchase `date` falls within the supplied boundaries
- **AND** each global row displays its purchase's business context
- **AND** the active session setting remains irrelevant

#### Scenario: Search supports operational purchase identifiers and descriptions
- **WHEN** an authorized user searches the global list
- **THEN** matching can use purchase reference, supplier purchase number, supplier reference number, tax reference, supplier name, product name/code, purchase note, or tag
- **AND** a search match does not bypass the selected business, date, lifecycle, archival, or summary-card filters

#### Scenario: Summary card filters refine the selected list
- **WHEN** a user selects the outstanding, overdue, or paid summary card while other global list filters are active
- **THEN** the table retains the active business, document-date, and text-search filters
- **AND** the outstanding card lists only purchases with a positive current live outstanding balance
- **AND** the overdue card additionally lists only purchases whose due date is before today
- **AND** the paid card lists only fully paid purchases that have an active payment within the displayed recent 30-day period

#### Scenario: List omits unrelated purchase operations
- **WHEN** an authorized user views a payable row on the global payment list
- **THEN** the page does not expose purchase creation, import, update, deletion, approval, receiving, duplication, archive, or attachment-management actions
- **AND** the available row actions are limited to the global read-only purchase detail and payment-related actions allowed by the user's permissions

#### Scenario: Fully paid rows remain inspectable but cannot create payment
- **WHEN** an authorized user views a fully paid row on the global payment list
- **THEN** global read-only purchase detail and payment history remain available
- **AND** no create-payment action is displayed or accepted

#### Scenario: Create payment opens supplier allocation page
- **WHEN** a user with `purchasePayments.create` selects `Buat Pembayaran` for a payable eligible purchase
- **THEN** the system redirects to the global supplier multi-payment page with that purchase as the starting purchase

### Requirement: Dedicated cross-setting read-only purchase detail
The system SHALL provide a dedicated global purchase-detail route and context that can display a purchase selected from `Pembayaran Pembelian Global` without applying the active session setting restriction, while leaving the normal setting-scoped purchase-detail route unchanged.

#### Scenario: User opens a purchase belonging to another setting
- **WHEN** an authorized user selects a base-eligible paid or payable purchase whose `setting_id` differs from the active session setting
- **THEN** the dedicated global detail route displays that purchase, its receiving information, and its payment history
- **AND** the request is not rejected solely because the purchase belongs to another setting

#### Scenario: Global detail remains read-only except for payable payment creation
- **WHEN** an authorized user views a purchase through the global detail context
- **THEN** update, delete, approval, receiving, duplication, archive, and attachment-management controls are absent
- **AND** payment creation is available only when the purchase has a positive current live outstanding balance and the user has `purchasePayments.create`
- **AND** a fully paid purchase remains inspectable but cannot open or submit a new payment

#### Scenario: Global detail payment action opens multi-payment form
- **WHEN** an authorized user selects `Tambah Pembayaran` from a payable global purchase detail
- **THEN** the system redirects to the same supplier multi-payment page used by the global list action
- **AND** the viewed purchase becomes the starting purchase

#### Scenario: Normal purchase detail remains setting-scoped
- **WHEN** a user requests the existing normal purchase-detail route for a purchase outside the active setting
- **THEN** the existing setting ownership guard continues to reject the request
- **AND** global access is granted only through the dedicated authorized global route

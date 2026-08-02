# global-purchase-multi-payment Specification

## Purpose
TBD - created by archiving change add-global-purchase-multi-payment. Update Purpose after archive.
## Requirements
### Requirement: Global purchase payment navigation and authorization
The system SHALL expose an operational menu named `Pembayaran Pembelian Global` beside `Semua Pembelian` under the `Pembelian` menu, and SHALL protect its list, detail, payment-form, and submission routes with defined purchase-payment permissions.

#### Scenario: Authorized user sees the operational menu
- **WHEN** an authenticated user has `purchasePayments.global.access`
- **THEN** the `Pembelian` menu contains `Pembayaran Pembelian Global` beside `Semua Pembelian`
- **AND** the menu links to the operational global purchase-payment list rather than the global purchase report under `Laporan`

#### Scenario: Unauthorized user cannot discover or access the workspace
- **WHEN** an authenticated user does not have `purchasePayments.global.access`
- **THEN** the `Pembayaran Pembelian Global` menu is hidden
- **AND** direct requests to its list, global purchase detail, and payment form are forbidden

#### Scenario: Payment submission requires create permission
- **WHEN** a user has `purchasePayments.global.access` but does not have `purchasePayments.create`
- **THEN** the user can view the global payment list and read-only purchase detail
- **AND** create-payment actions are hidden
- **AND** a direct multi-payment submission is forbidden

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

### Requirement: Sample-inspired supplier multi-payment interface
The system SHALL present a supplier payment form inspired by `report-sample/pembayaran/pembelian-ui.txt`, implemented with the existing ERP Bootstrap/CoreUI conventions and excluding sample fields unsupported by the current purchase-payment domain.

#### Scenario: Supported shared payment fields are displayed
- **WHEN** an authorized user opens the multi-payment form from an eligible starting purchase
- **THEN** the page displays the supplier as read-only, transaction date, transaction reference, payment method, memo, one attachment input, subtotal, total, cancel, and save controls
- **AND** the page does not display unsupported tag, withholding, separate payment due-date, or multiple-attachment controls

#### Scenario: Eligible supplier purchases are displayed as allocation rows
- **WHEN** the multi-payment form loads
- **THEN** it lists non-archived purchases with the starting purchase's exact `supplier_id`, exact status `RECEIVED`, and positive current outstanding balance
- **AND** the candidate query does not apply a `setting_id` restriction
- **AND** each row displays transaction number, description, due date, total, outstanding balance, and an editable payment amount

#### Scenario: Initial allocations follow the sample behavior
- **WHEN** the multi-payment form first loads
- **THEN** the starting purchase amount defaults to its full current outstanding balance
- **AND** every other candidate purchase amount defaults to zero
- **AND** subtotal and total equal the sum of positive allocation amounts

#### Scenario: Ineligible starting purchase is rejected
- **WHEN** the requested starting purchase is archived, is not exactly `RECEIVED`, or has no positive current outstanding balance
- **THEN** the system does not render a payable allocation form
- **AND** no purchase payment is created

### Requirement: Multi-purchase allocation validation
The system MUST validate the submitted allocation against current server-side purchase and active-payment data rather than trusting rendered balances or client-provided purchase metadata.

#### Scenario: At least one positive allocation is required
- **WHEN** all submitted allocation amounts are zero or blank
- **THEN** validation fails with an Indonesian user-facing message
- **AND** no purchase payment is created

#### Scenario: Positive allocations cannot exceed live balances
- **WHEN** any submitted amount is negative or exceeds that purchase's current outstanding balance
- **THEN** the complete submission is rejected
- **AND** no purchase payment is created for any row

#### Scenario: Tampered or newly ineligible candidate is rejected
- **WHEN** a submitted purchase has another `supplier_id`, is outside exact status `RECEIVED`, is archived, is not an allowed candidate, or becomes fully paid before submission
- **THEN** the complete submission is rejected
- **AND** no payment is applied to any selected purchase

#### Scenario: Zero allocations are ignored
- **WHEN** a valid submission contains both positive and zero allocation amounts
- **THEN** the system creates payments only for rows with positive amounts
- **AND** zero-allocation purchases remain unchanged

### Requirement: Atomic reuse of existing purchase payments
The system SHALL create one existing `PurchasePayment` record per positive allocation and SHALL update every affected purchase using the existing active-payment balance and payment-status semantics in one coordinated operation.

#### Scenario: Successful multi-purchase payment
- **WHEN** an authorized user submits valid positive allocations for multiple eligible purchases
- **THEN** one active `PurchasePayment` is created for each positive allocation and linked to its corresponding purchase
- **AND** every payment receives the shared transaction date, reference, payment method, and memo
- **AND** every affected purchase has its effective paid amount, outstanding amount, and payment status recalculated from active payments

#### Scenario: Database failure rolls back all allocations
- **WHEN** creating or recalculating any payment allocation fails
- **THEN** all database changes from the submission are rolled back
- **AND** no affected purchase is left partially settled by that submission

#### Scenario: Concurrent balance change is detected
- **WHEN** an outstanding balance changes after the form was rendered but before the submission is committed
- **THEN** the system locks and revalidates the affected purchases against their current active payments
- **AND** it rejects any now-invalid allocation without partially creating the remaining payments

### Requirement: Shared attachment is replicated to every payment
The system SHALL accept at most one supported attachment for a multi-purchase submission and SHALL append an independent copy of that attachment to every generated `PurchasePayment` attachment collection.

#### Scenario: Attachment is copied to all generated payments
- **WHEN** a valid multi-purchase submission includes one attachment and creates more than one payment
- **THEN** every created payment contains the attachment through the existing payment attachment mechanism
- **AND** each payment can display or retrieve its own attachment independently

#### Scenario: Submission without attachment remains valid
- **WHEN** a valid multi-purchase submission contains no attachment
- **THEN** all allocated payments are created without media
- **AND** payment creation otherwise follows the same behavior

#### Scenario: Attachment replication failure leaves no partial result
- **WHEN** the attachment cannot be copied to any generated payment
- **THEN** no payment from the submission remains committed
- **AND** any files or media records already prepared for that failed submission are cleaned up

### Requirement: Explicit global purchase payment filter application
The system SHALL present the global purchase-payment business and document-date controls as a single filter panel with explicit application, reset, and applied-state feedback.

#### Scenario: User applies a purchase document-date filter
- **WHEN** an authorized user enters a business and/or one or both `Tanggal Dokumen` boundaries and selects `Terapkan Filter`
- **THEN** the global purchase table and summary cards refresh using the applied values
- **AND** the table contains only otherwise eligible purchases whose `date` satisfies every supplied inclusive boundary
- **AND** the workspace visibly identifies the active applied filters or filtered result state

#### Scenario: Draft values do not change the purchase list prematurely
- **WHEN** an authorized user changes a business or document-date control before selecting `Terapkan Filter`
- **THEN** the existing table, summaries, and applied-state feedback remain based on the previously applied values

#### Scenario: User supplies an incomplete or reversed purchase date range
- **WHEN** an authorized user applies only one date boundary or enters a from date later than the to date
- **THEN** the system applies the supplied single boundary or normalizes the two boundaries into chronological order
- **AND** the visible controls and applied-state feedback reflect the effective range

#### Scenario: User resets purchase filters
- **WHEN** an authorized user selects `Reset` in the global purchase-payment filter panel
- **THEN** business and document-date filters are cleared from draft and applied state
- **AND** the table and summaries return to their unfiltered eligible global results

### Requirement: Global purchase payment state survives page refresh
The system SHALL restore the full applied filter and summary-card selection state of the global purchase-payment workspace from its shareable URL, so that after a page refresh the table results, summary-card totals, and visible card highlight all match the restored state.

#### Scenario: Refresh with applied filters and card selection
- **WHEN** an authorized user refreshes a global purchase-payment URL that encodes an applied business filter, document-date boundaries, and a selected summary card
- **THEN** the table SHALL show only purchases satisfying both the applied filters and the selected card's payment-state condition
- **AND** the summary cards SHALL compute their totals using the same applied filters
- **AND** the previously selected card SHALL remain visibly selected

#### Scenario: Refresh with no encoded state
- **WHEN** an authorized user loads the global purchase-payment page without filter or selection parameters
- **THEN** the table and summary cards SHALL show the unfiltered eligible global results with no card selected

### Requirement: Global purchase filter controls use the application form styling
The system SHALL render the global purchase-payment filter controls (business selector, document-date inputs, per-page selector) using form styles supported by the application's loaded CSS framework so that all controls appear visually consistent with the rest of the application.

#### Scenario: Business selector renders styled
- **WHEN** an authorized user views the global purchase-payment filter panel
- **THEN** the business dropdown and per-page selector SHALL render with the application's standard select styling rather than unstyled browser defaults


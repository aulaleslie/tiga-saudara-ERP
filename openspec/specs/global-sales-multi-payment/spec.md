# global-sales-multi-payment Specification

## Purpose
TBD - created by archiving change add-global-sales-multi-payment. Update Purpose after archive.
## Requirements
### Requirement: Global sales payment navigation and authorization
The system SHALL expose an operational menu named `Pembayaran Penjualan Global` under the Sales menu and SHALL protect its list, detail, history, payment-form, and submission routes with sales-payment permissions.

#### Scenario: Authorized user sees the operational workspace
- **WHEN** an authenticated user has `salePayments.global.access`
- **THEN** the Sales menu contains `Pembayaran Penjualan Global`
- **AND** the menu links to the global sales-payment list

#### Scenario: Unauthorized user cannot discover or access the workspace
- **WHEN** an authenticated user does not have `salePayments.global.access`
- **THEN** the menu is hidden
- **AND** direct requests to global list, detail, history, form, and submission routes are forbidden

#### Scenario: Payment submission requires create permission
- **WHEN** a user has `salePayments.global.access` but does not have `salePayments.create`
- **THEN** the user can view the global list, read-only detail, and payment history
- **AND** create-payment actions are hidden
- **AND** direct form and submission requests are forbidden

### Requirement: Payment-focused global sales list
The system SHALL provide a sales-payment list using the established sales table behavior while querying eligible sales across all settings.

#### Scenario: List is independent of the active setting
- **WHEN** an authorized user opens `Pembayaran Penjualan Global`
- **THEN** eligible sales from every `setting_id` can be listed
- **AND** the active session setting does not restrict the results

#### Scenario: Eligible paid and payable sales are listed
- **WHEN** the global list is queried without a selected summary card
- **THEN** it contains non-archived sales whose exact status is `APPROVED`, `DISPATCHED PARTIALLY`, `DISPATCHED`, or `RETURNED PARTIALLY`
- **AND** both sales with a positive current live outstanding balance and fully paid sales with a live outstanding balance less than or equal to zero are listed
- **AND** draft, waiting-approval, rejected, fully `RETURNED`, and archived sales are excluded

#### Scenario: Summary card filters refine the selected list
- **WHEN** a user selects the outstanding, overdue, or paid summary card while other global list filters are active
- **THEN** the table retains the active business, document-date, and text-search filters
- **AND** the outstanding card lists only eligible sales with a positive current live outstanding balance
- **AND** the overdue card additionally lists only eligible sales whose due date is before today
- **AND** the paid card lists only eligible fully paid sales that have an active payment within the displayed recent 30-day period

### Requirement: Global sales payment summaries
The system SHALL provide payment-focused summary cards based on eligible sales across all settings.

#### Scenario: Outstanding and overdue summaries use live balances
- **WHEN** global summary cards are rendered
- **THEN** outstanding and overdue counts and totals use eligible sales and their positive live outstanding balances across all settings

#### Scenario: Recent collection summary uses active payments
- **WHEN** the recent collection card is rendered
- **THEN** it uses active sale payments in the defined recent period for eligible sales across all settings
- **AND** invalidated payments are excluded

#### Scenario: Summary filter updates the global list
- **WHEN** a user selects an outstanding, overdue, or recently paid summary
- **THEN** the global table applies the corresponding cross-setting payment filter

### Requirement: Dedicated cross-setting read-only sale inspection
The system SHALL provide dedicated global detail and payment-history contexts that do not apply the active session setting restriction.

#### Scenario: User opens a sale belonging to another setting
- **WHEN** an authorized user opens a base-eligible paid or payable sale whose setting differs from the active session setting
- **THEN** the global detail displays the sale and related dispatch and payment information
- **AND** the page presents company information from the sale's actual setting

#### Scenario: Global detail is read-only except for payable payment creation
- **WHEN** an authorized user views a sale through the global detail route
- **THEN** sale update, approval, dispatch, archive, delete, and attachment-management controls are absent
- **AND** payment creation is available only when the sale has a positive current live outstanding balance and the user has `salePayments.create`
- **AND** a fully paid sale remains inspectable but cannot open or submit a new payment

#### Scenario: Normal sale routes remain setting-scoped
- **WHEN** a user requests an existing normal sale route for a sale outside the active setting
- **THEN** the normal ownership behavior remains in force
- **AND** global payment access is granted only through dedicated routes protected by `salePayments.global.access`

### Requirement: Customer multi-invoice monetary payment interface
The system SHALL present a customer-based form for allocating one monetary payment across eligible sales.

#### Scenario: Shared payment fields are displayed
- **WHEN** an authorized user opens the form from an eligible starting sale
- **THEN** the page displays the customer as read-only, payment date, reference, payment method, memo, one optional attachment, allocation total, cancel, and save controls

#### Scenario: Candidate invoices use exact customer identity
- **WHEN** the allocation form loads
- **THEN** it lists eligible sales with the starting sale’s exact `customer_id` across all settings
- **AND** each row displays sale reference, setting or company context, due date, total, live outstanding balance, and editable monetary allocation
- **AND** available POS receipt or transaction identifiers are displayed for POS-originated sales

#### Scenario: Initial allocation selects the starting sale
- **WHEN** the form first loads
- **THEN** the starting sale defaults to its full live outstanding balance
- **AND** all other candidates default to zero
- **AND** the displayed total equals the sum of positive allocations

#### Scenario: Ineligible starting sale is rejected
- **WHEN** the requested starting sale has another lifecycle status, is archived, or has no positive live outstanding balance
- **THEN** the system does not render a payable allocation form
- **AND** no payment is created

### Requirement: Global workflow excludes customer credit
The system MUST process only monetary payment-method allocations and MUST NOT apply customer credit through the global workflow.

#### Scenario: Form does not offer customer credit
- **WHEN** the global allocation form is rendered for a customer with open credits
- **THEN** customer-credit balances and selectors are not displayed

#### Scenario: Submission creates no credit applications
- **WHEN** a valid global monetary payment is submitted
- **THEN** no `SalePaymentCreditApplication` is created
- **AND** no `CustomerCredit` balance or status is changed

#### Scenario: Existing single-sale credit workflow remains available
- **WHEN** an authorized user uses the existing single-sale payment flow
- **THEN** its existing customer-credit behavior remains unchanged

### Requirement: Server-authoritative allocation validation
The system MUST validate submitted allocations against current locked sale and active-payment data rather than trusting rendered balances or client metadata.

#### Scenario: Customer mismatch or changed eligibility rejects the batch
- **WHEN** a submitted sale belongs to another customer, is archived, has a status other than `APPROVED`, `DISPATCHED PARTIALLY`, `DISPATCHED`, or `RETURNED PARTIALLY`, or becomes fully paid
- **THEN** the complete submission is rejected
- **AND** no selected sale is partially settled

#### Scenario: Fully returned sale is rejected even with a legacy positive balance
- **WHEN** a submitted sale has exact status `RETURNED`
- **THEN** the complete submission is rejected regardless of its stored or live balance
- **AND** no sale payment is created

### Requirement: Canonical live sales balance
The system SHALL derive global eligibility and reconciliation from canonical active settlement data and SHALL not rely only on a previously stored `due_amount`.

#### Scenario: Invalidated payments do not reduce live due
- **WHEN** a sale has an invalidated sale payment
- **THEN** that payment is excluded from the active monetary paid total
- **AND** the global live outstanding balance reflects its exclusion

#### Scenario: Existing credit settlement is preserved
- **WHEN** a sale already has valid customer-credit applications from the single-sale workflow
- **THEN** global payment reconciliation preserves the effect of those existing credits
- **AND** it neither erases nor double-counts their settlement

#### Scenario: Header status is reconciled after payment
- **WHEN** a global allocation is committed
- **THEN** the affected sale’s paid amount, due amount, and payment status are reconciled from canonical settlement totals
- **AND** the status is `UNPAID`, `PARTIAL`, or `PAID` according to the remaining balance

### Requirement: Atomic reuse of existing sale payments
The system SHALL create one existing active `SalePayment` per positive allocation and commit the complete customer payment atomically.

#### Scenario: Successful multi-sale payment
- **WHEN** an authorized user submits valid allocations for multiple eligible sales
- **THEN** one active `SalePayment` is linked to each positively allocated sale
- **AND** every created payment receives the shared date, reference, payment method, and memo
- **AND** all affected sale balances and statuses are reconciled

#### Scenario: Database failure rolls back every allocation
- **WHEN** creating a payment or reconciling any affected sale fails
- **THEN** all database changes from the submission are rolled back
- **AND** no sale remains partially settled by the failed batch

### Requirement: Shared attachment replication
The system SHALL accept at most one supported attachment and SHALL give every generated sale payment an independently accessible copy.

#### Scenario: Attachment is copied to all payments
- **WHEN** one valid submission with an attachment creates multiple payments
- **THEN** each created payment contains the attachment through the existing media collection

#### Scenario: Attachment is optional
- **WHEN** a valid submission has no attachment
- **THEN** all allocated payments are created without media
- **AND** payment processing otherwise succeeds

#### Scenario: Attachment failure is atomic
- **WHEN** the attachment cannot be copied to any generated payment
- **THEN** no payment from the submission remains committed
- **AND** partial media artifacts are cleaned up

### Requirement: POS Kas Bon participates through ordinary sales
The system SHALL include POS Kas Bon receivables through their generated `Sale` records without creating a separate POS payment ledger.

#### Scenario: Unpaid POS Kas Bon is eligible
- **WHEN** a POS Kas Bon sale is `DISPATCHED`, non-archived, and has positive live due
- **THEN** it appears in the global sales-payment workspace

#### Scenario: Fully paid POS checkout is excluded
- **WHEN** a POS-originated sale has no positive live outstanding balance
- **THEN** it does not appear as a payable candidate

#### Scenario: POS and ordinary sales can share one customer payment
- **WHEN** an ordinary sale and a POS Kas Bon sale have the same exact customer ID and are both eligible
- **THEN** both can receive positive allocations in one atomic submission

#### Scenario: Split-owner POS sales remain independent
- **WHEN** one POS checkout generated multiple owner-aligned sales
- **THEN** each eligible generated sale appears and is validated as a separate allocation row
- **AND** settling one row does not implicitly settle another

#### Scenario: Payment is visible through the existing sale ledger
- **WHEN** a POS Kas Bon allocation is committed
- **THEN** it creates an ordinary `SalePayment` for the generated sale
- **AND** sale and POS views observe the reconciled outstanding balance

### Requirement: Explicit global sales payment filter application
The system SHALL present the global sales-payment business and document-date controls as a single filter panel with explicit application, reset, and applied-state feedback, and SHALL treat business selections as draft values until explicitly applied.

#### Scenario: User applies a sales document-date filter
- **WHEN** an authorized user selects zero or more businesses and/or enters one or both `Tanggal Dokumen` boundaries and selects `Terapkan Filter`
- **THEN** the global sales table and summary cards refresh using the applied values
- **AND** the table contains only otherwise eligible sales whose `setting_id` is selected when any businesses were selected and whose `date` satisfies every supplied inclusive boundary
- **AND** an empty applied business selection continues to include all businesses
- **AND** the workspace visibly identifies the active applied filters or filtered result state

#### Scenario: Draft values do not change the sales list prematurely
- **WHEN** an authorized user changes the multi-business selector or document-date controls before selecting `Terapkan Filter`
- **THEN** the existing table, summaries, URL state, and applied-state feedback remain based on the previously applied values

#### Scenario: User supplies an incomplete or reversed sales date range
- **WHEN** an authorized user applies only one date boundary or enters a from date later than the to date
- **THEN** the system applies the supplied single boundary or normalizes the two boundaries into chronological order
- **AND** the visible controls and applied-state feedback reflect the effective range

#### Scenario: User resets sales filters
- **WHEN** an authorized user selects `Reset semua filter` in the global sales-payment filter panel
- **THEN** business and document-date filters are cleared from draft and applied state
- **AND** the client-side multi-business selector visibly shows no selected businesses
- **AND** the table and summaries return to their unfiltered eligible global results

### Requirement: Durable global sales summary-card selection
The system SHALL preserve the visible selected summary-card state while global sales filters or summaries refresh.

#### Scenario: Selected card remains visible after filter application
- **WHEN** an authorized user selects an outstanding, overdue, or paid summary card and then applies a business or document-date filter
- **THEN** the same card remains visibly selected
- **AND** its payment-state condition remains composed with the applied filters, text search, and eligible-sales constraints

### Requirement: Global sales payment state survives page refresh
The system SHALL restore the full applied filter and summary-card selection state of the global sales-payment workspace from its shareable URL, so that after a page refresh the table results, summary-card totals, visible filter controls, and card highlight all match the restored state.

#### Scenario: Refresh with applied filters and card selection
- **WHEN** an authorized user refreshes a global sales-payment URL that encodes applied business selections, document-date boundaries, and a selected summary card
- **THEN** the table SHALL show only sales satisfying both the applied filters and the selected card's payment-state condition
- **AND** the summary cards SHALL compute their totals using the same applied filters
- **AND** every encoded business SHALL appear selected in the multi-business control
- **AND** the previously selected card SHALL remain visibly selected

#### Scenario: Refresh with no encoded state
- **WHEN** an authorized user loads the global sales-payment page without filter or selection parameters
- **THEN** the table and summary cards SHALL show the unfiltered eligible global results with no card selected
- **AND** the multi-business control SHALL visibly show no selection to represent all businesses

### Requirement: Global sales filter controls use the application form styling
The system SHALL render the global sales-payment filter controls using the application's loaded Select2 and CoreUI-compatible form styles so that all controls appear visually consistent with the Laporan Laba Rugi business selector and the rest of the application.

#### Scenario: Business selector renders as a searchable multi-select
- **WHEN** an authorized user views the global sales-payment filter panel
- **THEN** the business selector SHALL render as a searchable Select2/CoreUI multi-select rather than an unstyled native multi-select
- **AND** selected businesses SHALL render as individually removable choices
- **AND** the per-page selector and date inputs SHALL retain application-supported form styling

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


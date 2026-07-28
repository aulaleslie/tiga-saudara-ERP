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

#### Scenario: Approved-up outstanding sales are eligible
- **WHEN** the global list is queried
- **THEN** it contains only non-archived sales whose exact status is `APPROVED`, `DISPATCHED PARTIALLY`, or `DISPATCHED`
- **AND** each listed sale has a positive current live outstanding balance
- **AND** fully paid, draft, waiting-approval, rejected, returned, and archived sales are excluded

#### Scenario: Search retains ordinary and POS identifiers
- **WHEN** an authorized user searches the global list
- **THEN** matching can use sale reference, imported sale reference, tax reference, customer, product, tag, POS receipt number, or POS transaction code

#### Scenario: Global rows expose payment-only actions
- **WHEN** an authorized user views a global sales row
- **THEN** the available actions are limited to read-only detail, payment history, and allowed payment creation
- **AND** ordinary sale creation, editing, deletion, approval, dispatch, duplication, archive, and attachment-management actions are absent

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
- **WHEN** an authorized user opens an eligible sale whose setting differs from the active session setting
- **THEN** the global detail displays the sale and related dispatch and payment information
- **AND** the page presents company information from the sale’s actual setting

#### Scenario: Global detail is read-only except for payment
- **WHEN** an authorized user views a sale through the global detail route
- **THEN** sale update, approval, dispatch, archive, delete, and attachment-management controls are absent
- **AND** payment creation is available only when the sale remains eligible and the user has `salePayments.create`

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

#### Scenario: At least one positive allocation is required
- **WHEN** every submitted allocation is zero or blank
- **THEN** validation fails with an Indonesian user-facing message
- **AND** no sale payment is created

#### Scenario: Invalid amount rejects the complete batch
- **WHEN** any allocation is negative or exceeds that sale’s current live outstanding balance
- **THEN** the complete submission is rejected
- **AND** no payment is created for any allocation

#### Scenario: Customer mismatch or changed eligibility rejects the batch
- **WHEN** a submitted sale belongs to another customer, is archived, has an ineligible status, or becomes fully paid
- **THEN** the complete submission is rejected
- **AND** no selected sale is partially settled

#### Scenario: Zero allocations are ignored
- **WHEN** a valid submission contains positive and zero allocations
- **THEN** payments are created only for positive allocations
- **AND** zero-allocation sales remain unchanged

#### Scenario: Concurrent balance change is detected
- **WHEN** a sale’s outstanding balance changes after form rendering but before commit
- **THEN** the system locks and revalidates the sale against current settlement data
- **AND** any now-invalid allocation causes the complete batch to roll back

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


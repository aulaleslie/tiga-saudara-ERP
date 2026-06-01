## ADDED Requirements

### Requirement: Purchase imports create payment ledger rows
The purchase importer SHALL create one active `purchase_payments` row for each future imported purchase document whose resolved imported paid amount is greater than zero.

#### Scenario: Fully paid purchase import creates one payment
- **WHEN** a purchase CSV invoice group imports successfully with `Pembayaran` equal to the calculated document total and preferred outstanding balance equal to zero
- **THEN** the system MUST create one active `purchase_payments` row for the imported purchase
- **AND** the payment amount MUST equal the resolved imported paid amount
- **AND** the payment date MUST equal the imported purchase date
- **AND** the payment reference MUST equal the generated ERP purchase reference
- **AND** the payment method MUST reference an existing cash payment method
- **AND** the purchase `paid_amount`, `due_amount`, and `payment_status` MUST reconcile with the active payment row

#### Scenario: Partially paid purchase import creates one partial payment
- **WHEN** a purchase CSV invoice group imports successfully with a positive `Pembayaran` less than the calculated document total
- **THEN** the system MUST create one active `purchase_payments` row for the imported purchase
- **AND** the payment amount MUST equal the resolved imported paid amount
- **AND** the purchase MUST retain a positive `due_amount`
- **AND** the purchase payment status MUST indicate partial payment using the existing purchase payment status convention

#### Scenario: Unpaid purchase import creates no payment
- **WHEN** a purchase CSV invoice group imports successfully with resolved imported paid amount equal to zero
- **THEN** the system MUST NOT create a `purchase_payments` row for the imported purchase
- **AND** the purchase `paid_amount` MUST be zero
- **AND** the purchase `due_amount` MUST equal the calculated document total

### Requirement: Sales imports create payment ledger rows
The sales importer SHALL create one active `sale_payments` row for each future imported sale document whose resolved imported paid amount is greater than zero.

#### Scenario: Fully paid sales import creates one payment
- **WHEN** a sales CSV invoice group imports successfully with `Pembayaran` equal to the calculated document total and preferred outstanding balance equal to zero
- **THEN** the system MUST create one active `sale_payments` row for the imported sale
- **AND** the payment amount MUST equal the resolved imported paid amount
- **AND** the payment date MUST equal the imported sale date
- **AND** the payment reference MUST equal the generated ERP sale reference
- **AND** the payment method MUST reference an existing cash payment method
- **AND** the sale `paid_amount`, `due_amount`, and `payment_status` MUST reconcile with the active payment row

#### Scenario: Partially paid sales import creates one partial payment
- **WHEN** a sales CSV invoice group imports successfully with a positive `Pembayaran` less than the calculated document total
- **THEN** the system MUST create one active `sale_payments` row for the imported sale
- **AND** the payment amount MUST equal the resolved imported paid amount
- **AND** the sale MUST retain a positive `due_amount`
- **AND** the sale payment status MUST indicate partial payment using the existing sales payment status convention

#### Scenario: Unpaid sales import creates no payment
- **WHEN** a sales CSV invoice group imports successfully with resolved imported paid amount equal to zero
- **THEN** the system MUST NOT create a `sale_payments` row for the imported sale
- **AND** the sale `paid_amount` MUST be zero
- **AND** the sale `due_amount` MUST equal the calculated document total

### Requirement: Import payment amount resolution
The purchase and sales importers SHALL resolve imported paid amount from `Pembayaran` when it is present and non-blank, and SHALL fall back to calculated document total minus preferred outstanding balance only when `Pembayaran` is blank or missing.

#### Scenario: Pembayaran is used when present
- **WHEN** an invoice group includes a non-blank `Pembayaran` value
- **THEN** the importer MUST use that value as the resolved imported paid amount
- **AND** the importer MUST validate that the resolved paid amount plus preferred outstanding balance reconciles with the calculated document total

#### Scenario: Missing Pembayaran falls back to outstanding balance
- **WHEN** an invoice group has no usable `Pembayaran` value
- **THEN** the importer MUST calculate resolved imported paid amount as calculated document total minus preferred outstanding balance
- **AND** the importer MUST NOT require the missing `Pembayaran` column to import an otherwise valid invoice group

#### Scenario: Sisa Tagihan Hari Ini is preferred
- **WHEN** an invoice group includes both `Sisa Tagihan Hari Ini` and `Sisa Tagihan`
- **THEN** the importer MUST use `Sisa Tagihan Hari Ini` as the preferred outstanding balance

#### Scenario: Sisa Tagihan is fallback
- **WHEN** an invoice group does not include a usable `Sisa Tagihan Hari Ini` value
- **THEN** the importer MUST use `Sisa Tagihan` as the preferred outstanding balance when it is present

### Requirement: Invoice-group payment validation
The purchase and sales importers SHALL validate repeated document-level payment fields at invoice and owner group scope before creating an imported purchase, sale, or payment row.

#### Scenario: Line-oriented invoice creates one document payment
- **WHEN** multiple CSV rows belong to the same invoice and owner group
- **AND** the group imports as one purchase or sale document
- **THEN** the system MUST create at most one payment row for that imported document
- **AND** the system MUST NOT create one payment row per CSV line

#### Scenario: Repeated payment fields must agree
- **WHEN** rows in the same invoice and owner group contain conflicting non-blank values for `Pembayaran`, preferred outstanding balance, or source document total
- **THEN** the importer MUST mark the whole invoice group invalid
- **AND** the importer MUST NOT create the purchase or sale document
- **AND** the importer MUST NOT create any payment row for that invoice group

#### Scenario: Payment totals must reconcile
- **WHEN** the resolved imported paid amount plus preferred outstanding balance does not reconcile with the calculated imported document total within the accepted monetary tolerance
- **THEN** the importer MUST mark the whole invoice group invalid
- **AND** the row error message MUST identify the payment total mismatch

### Requirement: Import payment method resolution
The purchase and sales importers SHALL use an existing cash payment method when creating imported payment rows and SHALL fail paid invoice groups when no cash payment method is available.

#### Scenario: Existing cash method is assigned
- **WHEN** an imported purchase or sale requires a payment row
- **AND** an existing payment method is marked as cash
- **THEN** the created payment row MUST reference that cash payment method

#### Scenario: Missing cash method fails paid group
- **WHEN** an imported purchase or sale has a resolved imported paid amount greater than zero
- **AND** no cash payment method can be resolved
- **THEN** the importer MUST mark the whole invoice group invalid
- **AND** the importer MUST NOT create the purchase or sale document
- **AND** the importer MUST NOT create any payment row for that invoice group

#### Scenario: Missing cash method does not block unpaid group
- **WHEN** an imported purchase or sale has a resolved imported paid amount equal to zero
- **AND** no cash payment method can be resolved
- **THEN** the missing cash payment method MUST NOT block the unpaid import solely because no payment row is needed

### Requirement: Historical imports are not backfilled
The system SHALL NOT create payment rows for purchases or sales that were imported before this change unless they are processed by a future import run.

#### Scenario: Deployment does not mutate old imports
- **WHEN** this change is deployed
- **THEN** existing imported purchases and sales MUST remain unchanged
- **AND** no migration or automatic job MUST create payment rows for historical imported documents

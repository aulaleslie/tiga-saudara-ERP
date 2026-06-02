## ADDED Requirements

### Requirement: Source invoice payment reconciliation before owner split creation
The purchase and sales importers SHALL reconcile invoice-level `Total`, `Pembayaran`, `Sisa Tagihan`, and `Sisa Tagihan Hari Ini` at source invoice scope before creating owner-split documents.

#### Scenario: Full source invoice total reconciles across owner groups
- **WHEN** a source CSV invoice contains rows that resolve to more than one owner document
- **AND** the sum of all owner-group adjusted totals equals the repeated source `Total`
- **THEN** the importer MUST treat the source invoice payment fields as reconciling
- **AND** the importer MUST NOT reject an owner group only because its individual total is less than the source `Total`

#### Scenario: Source total mismatch invalidates all invoice rows
- **WHEN** a source CSV invoice contains one or more owner groups
- **AND** the sum of owner-group adjusted totals does not reconcile with the repeated source `Total`
- **THEN** the importer MUST mark every row in that source invoice invalid
- **AND** the importer MUST NOT create purchase, sale, purchase payment, sale payment, stock, dispatch, receipt, or price records for any group in that source invoice

#### Scenario: Current paid status overrides stale original balance
- **WHEN** a source CSV invoice has `Status Hari Ini` equal to `Lunas`
- **AND** `Sisa Tagihan Hari Ini` equals `0.00`
- **AND** `Pembayaran` is `0.00` while `Sisa Tagihan` still equals the original source `Total`
- **THEN** the importer MUST treat the invoice as currently fully paid
- **AND** the generated document MUST have `paid_amount` equal to its total and `due_amount` equal to `0.00`
- **AND** the importer MUST create the active payment row needed for the generated paid document

### Requirement: Jumlah Pemotongan is reconciled as a non-cash settlement credit
The purchase and sales importers SHALL map a source `Jumlah Pemotongan` column and treat it as a non-cash settlement credit that, together with cash `Pembayaran` and outstanding balance, reconciles the source `Total`.

#### Scenario: Invoice with a deduction reconciles and imports
- **WHEN** a source CSV invoice has `Pembayaran` plus `Jumlah Pemotongan` plus outstanding balance equal to the source `Total`
- **AND** `Pembayaran` alone plus outstanding balance does not equal the source `Total`
- **THEN** the importer MUST treat the invoice as reconciling and MUST create the document
- **AND** the importer MUST NOT reject the invoice for a payment total mismatch

#### Scenario: Deduction is recorded as credit, not as cash
- **WHEN** a reconciling invoice carries a non-zero `Jumlah Pemotongan`
- **THEN** the generated document `paid_amount` MUST equal the cash `Pembayaran` plus the deduction so that `paid_amount` plus `due_amount` equals the document total
- **AND** the importer MUST create exactly one cash payment row whose amount equals the cash `Pembayaran`
- **AND** the importer MUST create exactly one non-cash payment row, using a payment method with `is_cash = false`, whose amount equals the deduction
- **AND** the cash and non-cash payment row amounts MUST sum to the document `paid_amount`

#### Scenario: Reports show a deducted invoice as fully paid
- **WHEN** a reconciling fully-settled invoice carries a non-zero `Jumlah Pemotongan`
- **AND** the purchase report derives paid amounts from active payment rows
- **THEN** the report MUST show the invoice paid amount equal to the cash payment plus the deduction credit
- **AND** the report MUST show zero outstanding and a fully-paid status

#### Scenario: Deduction is allocated across split owners
- **WHEN** a reconciling invoice with a non-zero `Jumlah Pemotongan` splits into multiple positive-total owner documents
- **THEN** the importer MUST allocate the deduction across owner groups pro-rata by owner-group total
- **AND** the summed owner documents MUST still reconcile to the source invoice total

### Requirement: Pro-rata document discount and shipping allocation for split-owner imports
The purchase and sales importers SHALL treat repeated document-level `Diskon` and `Biaya Pengiriman` as a single source-invoice amount and SHALL allocate each across positive-total owner groups pro-rata by each group's gross line total (line totals plus tax, before document adjustment), assigning any two-decimal rounding remainder to the largest positive-total group.

#### Scenario: Document discount is allocated, not applied per group
- **WHEN** a source CSV invoice repeats a document-level `Diskon` (or `Biaya Pengiriman`) on every row
- **AND** the invoice splits into multiple positive-total owner documents
- **THEN** the importer MUST allocate the document amount across owner groups pro-rata by gross line total rather than applying the full amount to each group
- **AND** each owner document's adjusted total MUST equal its gross line total minus its allocated discount plus its allocated shipping
- **AND** the sum of owner-group adjusted totals MUST reconcile with the source `Total` even when the document discount or shipping is non-zero

#### Scenario: Persisted owner headers reconcile document adjustments
- **WHEN** a split-owner invoice carries a document-level discount or shipping amount
- **THEN** the sum of the generated owner documents' `discount_amount` values MUST equal the source document discount within monetary tolerance
- **AND** the sum of the generated owner documents' `shipping_amount` values MUST equal the source document shipping within monetary tolerance

#### Scenario: Single owner document keeps the full document adjustment
- **WHEN** a source CSV invoice resolves to a single positive-total owner document
- **THEN** that owner document MUST receive the full document-level discount and shipping amounts

### Requirement: Pro-rata payment allocation for split-owner imports
The purchase and sales importers SHALL allocate source invoice paid and outstanding amounts across positive-total owner documents pro-rata by each owner document's adjusted total.

#### Scenario: Fully paid split invoice creates fully paid owner documents
- **WHEN** a source CSV invoice is fully paid
- **AND** the invoice splits into multiple positive-total owner documents
- **THEN** every generated owner document MUST have `paid_amount` equal to its adjusted document total
- **AND** every generated owner document MUST have `due_amount` equal to `0.00`
- **AND** each positive-total owner document MUST receive exactly one active payment row for its allocated paid amount

#### Scenario: Partially paid split invoice allocates balances pro-rata
- **WHEN** a source CSV invoice is partially paid
- **AND** the invoice splits into multiple positive-total owner documents
- **THEN** each generated owner document MUST receive paid and due amounts proportional to its adjusted document total
- **AND** the sum of generated owner document `paid_amount` values MUST equal the source paid amount within monetary tolerance
- **AND** the sum of generated owner document `due_amount` values MUST equal the source outstanding amount within monetary tolerance

#### Scenario: Rounding remainder is allocated deterministically
- **WHEN** pro-rata allocation produces rounding differences after two-decimal rounding
- **THEN** the importer MUST assign the final rounding remainder to one positive-total owner group deterministically
- **AND** the persisted owner document totals MUST still sum to the source invoice paid and outstanding amounts within monetary tolerance

#### Scenario: Each owner document's settlement components reconcile to its own total
- **WHEN** a split-owner invoice allocates cash payment and a non-cash deduction across owner documents
- **THEN** the importer MUST allocate the cash, deduction, and due components so they sum to each owner's total
- **AND** every generated owner document MUST satisfy `paid_amount` plus `due_amount` equal to its own `total_amount` (no per-owner cent drift), where `paid_amount` is the cash plus deduction credit
- **AND** the invoice-level sums of cash, deduction, and due MUST still reconcile to the source values

#### Scenario: A tiny owner group is never over-settled into a negative due
- **WHEN** a split-owner invoice with a non-zero deduction includes an owner group whose total is very small relative to the others
- **THEN** that owner document's `due_amount` MUST NOT be negative
- **AND** the owner document's cash and deduction components MUST each be non-negative and MUST NOT sum to more than its `total_amount`
- **AND** the sum of that owner's active payment rows MUST NOT exceed its `total_amount`

### Requirement: Zero-total owner groups import without payment rows
The purchase and sales importers SHALL allow valid owner groups whose adjusted document total is `0.00` and SHALL create no payment row for those groups.

#### Scenario: Zero-total owner group is imported
- **WHEN** a source CSV invoice contains an owner group with adjusted document total `0.00`
- **AND** the source invoice as a whole reconciles
- **THEN** the importer MUST create the zero-total owner document for that group
- **AND** the generated document MUST have `paid_amount` equal to `0.00`
- **AND** the generated document MUST have `due_amount` equal to `0.00`
- **AND** the importer MUST NOT create a purchase payment or sale payment row for that zero-total document

#### Scenario: Zero-total rows preserve stock and audit behavior
- **WHEN** a valid zero-total owner group contains product rows with quantities
- **THEN** the importer MUST preserve the existing stock, receipt or dispatch, product, tag metadata, and transaction behavior for those rows
- **AND** payment allocation MUST NOT remove or merge those rows into a different owner document

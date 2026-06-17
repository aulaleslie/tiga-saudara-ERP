## MODIFIED Requirements

### Requirement: Source invoice payment reconciliation before owner split creation
The purchase and sales importers SHALL reconcile invoice-level `Total`, `Pembayaran`, `Sisa Tagihan`, and `Sisa Tagihan Hari Ini` at source invoice scope before creating owner-split documents. Sales import owner groups SHALL be based on Daizu/Kedelai ownership, product-name `*` marker ownership, product-name ` TP` suffix ownership, or `PERDANA` fallback, not CSV `Tag` values. For sales imports, any accepted source-total precision adjustment SHALL be allocated to owner groups before settlement allocation so each generated owner sale balances independently.

#### Scenario: Full source invoice total reconciles across owner groups
- **WHEN** a source CSV invoice contains rows that resolve to more than one owner document
- **AND** the sum of all owner-group adjusted totals equals the repeated source `Total`
- **THEN** the importer MUST treat the source invoice payment fields as reconciling
- **AND** the importer MUST NOT reject an owner group only because its individual total is less than the source `Total`

#### Scenario: Sales source total mismatch uses status mapping
- **WHEN** a sales source CSV invoice contains one or more owner groups
- **AND** the sum of owner-group adjusted totals does not reconcile with the repeated source `Total`
- **AND** the sales status mapping can derive paid and due amounts from source `Total` and `Status Hari Ini`
- **THEN** the sales importer MUST use source `Total` as the authoritative settlement total
- **AND** the importer MUST reconcile generated owner document totals to source `Total` before settlement allocation
- **AND** the importer MUST allocate the resolved paid, deduction, and due amounts across created owner sale documents using the existing split allocation rules

#### Scenario: Sales source-total rounding adjustment is applied before owner settlement allocation
- **WHEN** a sales source CSV invoice contains one or more owner groups
- **AND** source `Total` is authoritative through current-status mapping
- **AND** the source-reconciled invoice total requires an accepted precision adjustment
- **THEN** the sales importer MUST allocate the precision adjustment to owner group canonical totals before allocating cash, deduction, and due amounts
- **AND** each generated owner sale MUST satisfy paid amount plus due amount equals its canonical total
- **AND** the sum of generated owner sale totals MUST equal the authoritative source `Total` within monetary tolerance

#### Scenario: Current paid status overrides stale original balance
- **WHEN** a source CSV invoice has `Status Hari Ini` equal to `Lunas`
- **AND** `Sisa Tagihan Hari Ini` equals `0.00`
- **AND** `Pembayaran` is `0.00` while `Sisa Tagihan` still equals the original source `Total`
- **THEN** the importer MUST treat the invoice as currently fully paid
- **AND** the generated document MUST have `paid_amount` equal to its authoritative total and `due_amount` equal to `0.00`
- **AND** the importer MUST create the active payment row needed for the generated paid document

#### Scenario: Near-zero outstanding in scientific notation is treated as zero
- **WHEN** a source CSV invoice has `Status Hari Ini` equal to `Lunas`
- **AND** `Sisa Tagihan Hari Ini` is expressed in scientific notation that is effectively zero (e.g. `1.0e-06`)
- **AND** `Sisa Tagihan` still equals the original source `Total`
- **THEN** the importer MUST parse the scientific-notation value as a number rounding to `0.00` rather than discarding it
- **AND** the importer MUST treat the invoice as currently fully paid, not fall back to the stale full-balance `Sisa Tagihan`

#### Scenario: Fractional line quantity is preserved during reconciliation
- **WHEN** a source CSV invoice line has a fractional quantity (e.g. `23.7`)
- **THEN** the importer MUST compute that line's total using the fractional quantity, not a truncated integer
- **AND** the calculated sales source invoice total MUST reconcile with the repeated source `Total` after any authoritative source-total adjustment
- **AND** purchase source invoice totals MAY be reconciled to CSV `Total` through document-level adjustment when CSV `Status Hari Ini` and CSV `Total` are used as authoritative settlement input
- **AND** the persisted document detail MUST store the fractional quantity

#### Scenario: Fractional quantities persist without truncation
- **WHEN** the importer writes a fractional quantity to a document detail
- **THEN** the underlying column MUST store the value as a decimal rather than truncating it to an integer
- **AND** reading the persisted quantity back MUST return the same fractional value

### Requirement: Zero-total owner groups import without payment rows
The purchase and sales importers SHALL allow valid owner groups whose adjusted document total is `0.00` and SHALL create no payment row for those groups.

#### Scenario: Zero-total owner group is imported
- **WHEN** a source CSV invoice contains an owner group with adjusted document total `0.00`
- **AND** the source invoice as a whole reconciles
- **THEN** the importer MUST create the zero-total owner document for that group
- **AND** the generated document MUST have `paid_amount` equal to `0.00`
- **AND** the generated document MUST have `due_amount` equal to `0.00`
- **AND** the importer MUST NOT create a purchase payment or sale payment row for that zero-total document

#### Scenario: Zero-total purchase rows preserve document and catalog behavior without stock
- **WHEN** a valid zero-total purchase owner group contains product rows with quantities
- **THEN** the purchase importer MUST preserve the purchase detail, product, tag metadata, and last purchase price behavior for those rows
- **AND** the purchase importer MUST NOT create stock increments or inventory transactions for those rows
- **AND** payment allocation MUST NOT remove or merge those rows into a different owner document

#### Scenario: Zero-total sales rows preserve dispatch paperwork without stock
- **WHEN** a valid zero-total sales owner group contains product rows with quantities
- **THEN** the sales importer MUST preserve the sale detail, dispatch detail, product, and tag metadata behavior for those rows
- **AND** the sales importer MUST NOT create stock decrements or inventory transactions for those rows
- **AND** payment allocation MUST NOT remove or merge those rows into a different owner document

## ADDED Requirements

### Requirement: Sales product-name split invoices allocate settlement proportionally
The sales importer SHALL create separate owner sale documents for one source invoice when product-name owner markers differ, and SHALL allocate source invoice totals, paid amounts, deductions, due amounts, document discounts, and shipping proportionally by each owner group's adjusted item total.

#### Scenario: One sales header with three product-name owners creates three sales
- **WHEN** a sales CSV source invoice has shared header fields
- **AND** one item name begins with `*`
- **AND** one item name ends with ` TP`
- **AND** one item name has no owner marker
- **THEN** the importer MUST create one sale for `CV TIGA NUSA COMPUTER`
- **AND** the importer MUST create one sale for `CV TOP IT INTERNUSA`
- **AND** the importer MUST create one sale for `PERDANA`
- **AND** the sum of the generated sale `total_amount` values MUST equal the authoritative source `Total` within monetary tolerance

#### Scenario: Split sales payment components reconcile to source invoice
- **WHEN** a product-name split sales invoice imports successfully
- **AND** the source invoice has `Pembayaran`, `Jumlah Pemotongan`, or outstanding balance
- **THEN** each generated sale MUST receive proportional settlement components based on its adjusted total
- **AND** each generated sale MUST satisfy `paid_amount` plus `due_amount` equal to `total_amount`
- **AND** the sum of generated sale active payment rows MUST equal the source cash payment plus deduction credit within monetary tolerance
## Requirements
### Requirement: Source invoice payment reconciliation before owner split creation
The purchase and sales importers SHALL reconcile invoice-level `Total`, `Pembayaran`, `Sisa Tagihan`, and `Sisa Tagihan Hari Ini` at source invoice scope before creating owner-split documents. Sales import owner groups SHALL be based on Daizu/Kedelai ownership, product-name `*` marker ownership, product-name ` TP` suffix ownership, or `PERDANA` fallback, not CSV `Tag` values. For sales imports, all pending rows for the same source `no_faktur` in the batch SHALL be included in the reconciliation set before any owner sale is created, and any accepted source-total precision adjustment SHALL be allocated to owner groups before settlement allocation so each generated owner sale balances independently.

#### Scenario: Full source invoice total reconciles across owner groups
- **WHEN** a source CSV invoice contains rows that resolve to more than one owner document
- **AND** the sum of all owner-group adjusted totals equals the repeated source `Total`
- **THEN** the importer MUST treat the source invoice payment fields as reconciling
- **AND** the importer MUST NOT reject an owner group only because its individual total is less than the source `Total`

#### Scenario: Non-contiguous sales invoice rows reconcile together
- **WHEN** a sales import batch has pending rows with the same `no_faktur`
- **AND** those rows are not contiguous within the row-number ordering
- **THEN** the sales importer MUST reconcile those rows as one source invoice before owner split creation
- **AND** the importer MUST NOT process a partial source invoice only because the remaining same-invoice rows fall outside the initial chunk window

#### Scenario: Sales source total mismatch uses status mapping
- **WHEN** a sales source CSV invoice contains one or more owner groups
- **AND** the sum of owner-group adjusted totals does not reconcile with the repeated source `Total`
- **AND** the sales status mapping can derive paid and due amounts from source `Total` and `Status Hari Ini`
- **THEN** the sales importer MUST use source `Total` as the authoritative settlement total
- **AND** the importer MUST reconcile generated owner document totals to source `Total` before settlement allocation
- **AND** the importer MUST allocate the resolved paid, deduction, and due amounts across created owner sale documents using the existing split allocation rules

#### Scenario: Sales source-total rounding adjustment is applied before owner settlement allocation
- **WHEN** a sales source CSV invoice contains one or more owner groups
- **AND** source `Total` is authoritative through current-status mapping
- **AND** the source-reconciled invoice total requires an accepted precision adjustment
- **THEN** the sales importer MUST allocate the precision adjustment to owner group canonical totals before allocating cash, deduction, and due amounts
- **AND** each generated owner sale MUST satisfy paid amount plus due amount equals its canonical total
- **AND** the sum of generated owner sale totals MUST equal the authoritative source `Total` within monetary tolerance

#### Scenario: Split owner document discount allocation remains money exact
- **WHEN** a sales source invoice contains product-name owner groups and repeated document `Diskon`
- **AND** the source invoice as a whole reconciles to CSV `Total` after applying the document discount once
- **THEN** the sales importer MUST allocate the document discount into two-decimal canonical owner totals
- **AND** the sum of the owner canonical totals MUST equal the authoritative source `Total`
- **AND** settlement allocation MUST NOT fail only because fractional discount allocation created a one-cent owner-total remainder

#### Scenario: Current paid status overrides stale original balance
- **WHEN** a source CSV invoice has `Status Hari Ini` equal to `Lunas`
- **AND** `Sisa Tagihan Hari Ini` equals `0.00`
- **AND** `Pembayaran` is `0.00` while `Sisa Tagihan` still equals the original source `Total`
- **THEN** the importer MUST treat the invoice as currently fully paid
- **AND** the generated document MUST have `paid_amount` equal to its authoritative total and `due_amount` equal to `0.00`
- **AND** the importer MUST create the active payment row needed for the generated paid document

#### Scenario: Near-zero outstanding in scientific notation is treated as zero
- **WHEN** a source CSV invoice has `Status Hari Ini` equal to `Lunas`
- **AND** `Sisa Tagihan Hari Ini` is expressed in scientific notation that is effectively zero (e.g. `1.0e-06`)
- **AND** `Sisa Tagihan` still equals the original source `Total`
- **THEN** the importer MUST parse the scientific-notation value as a number rounding to `0.00` rather than discarding it
- **AND** the importer MUST treat the invoice as currently fully paid, not fall back to the stale full-balance `Sisa Tagihan`

#### Scenario: Fractional line quantity is preserved during reconciliation
- **WHEN** a source CSV invoice line has a fractional quantity (e.g. `23.7`)
- **THEN** the importer MUST compute that line's total using the fractional quantity, not a truncated integer
- **AND** the calculated sales source invoice total MUST reconcile with the repeated source `Total` after any authoritative source-total adjustment
- **AND** purchase source invoice totals MAY be reconciled to CSV `Total` through document-level adjustment when CSV `Status Hari Ini` and CSV `Total` are used as authoritative settlement input
- **AND** the persisted document detail MUST store the fractional quantity

#### Scenario: Fractional quantities persist without truncation
- **WHEN** the importer writes a fractional quantity to a document detail
- **THEN** the underlying column MUST store the value as a decimal rather than truncating it to an integer
- **AND** reading the persisted quantity back MUST return the same fractional value


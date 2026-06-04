## MODIFIED Requirements

### Requirement: Source invoice payment reconciliation before owner split creation
The purchase and sales importers SHALL reconcile invoice-level `Total`, `Pembayaran`, `Sisa Tagihan`, and `Sisa Tagihan Hari Ini` at source invoice scope before creating owner-split documents. Purchase import owner groups SHALL be based on Daizu/Kedelai ownership, mapped CSV `Tag`, or `PERDANA` fallback, not product-name markers.

#### Scenario: Full source invoice total reconciles across owner groups
- **WHEN** a source CSV invoice contains rows that resolve to more than one owner document
- **AND** the sum of all owner-group adjusted totals equals the repeated source `Total`
- **THEN** the importer MUST treat the source invoice payment fields as reconciling
- **AND** the importer MUST NOT reject an owner group only because its individual total is less than the source `Total`

#### Scenario: Purchase source total mismatch uses status mapping
- **WHEN** a purchase source CSV invoice contains one or more owner groups
- **AND** the sum of owner-group adjusted totals does not reconcile with the repeated source `Total`
- **AND** the purchase status mapping can derive paid and due amounts from source `Total` and `Status Hari Ini`
- **THEN** the purchase importer MUST use source `Total` as the authoritative settlement total
- **AND** the importer MUST allocate the resolved paid, deduction, and due amounts across created owner documents using the existing split allocation rules

#### Scenario: Sales source total mismatch invalidates all invoice rows
- **WHEN** a sales source CSV invoice contains one or more owner groups
- **AND** the sum of owner-group adjusted totals does not reconcile with the repeated source `Total`
- **THEN** the importer MUST mark every row in that source invoice invalid
- **AND** the importer MUST NOT create sale, sale payment, stock, dispatch, receipt, or price records for any group in that source invoice

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
- **AND** the calculated source invoice total MUST reconcile with the repeated source `Total` unless purchase status mapping uses CSV `Total` as authoritative settlement total
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

#### Scenario: Zero-total sales rows preserve stock and audit behavior
- **WHEN** a valid zero-total sales owner group contains product rows with quantities
- **THEN** the sales importer MUST preserve the existing stock, dispatch, product, tag metadata, and transaction behavior for those rows
- **AND** payment allocation MUST NOT remove or merge those rows into a different owner document

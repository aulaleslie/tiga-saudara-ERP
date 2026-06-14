## ADDED Requirements

### Requirement: Imports apply document-level discount and shipping once
The purchase and sales importers SHALL calculate imported document totals by applying document-level `Diskon` once and document-level `Biaya Pengiriman` once per invoice and owner group.

#### Scenario: Discounted purchase invoice reconciles to source total
- **WHEN** a purchase CSV invoice group has line totals that exceed `Total` by the repeated `Diskon` amount
- **THEN** the purchase importer MUST subtract the `Diskon` amount once from the calculated line total
- **AND** the adjusted document total MUST reconcile with source `Total`

#### Scenario: Discounted sales invoice reconciles to source total
- **WHEN** a sales CSV invoice group has line totals that exceed `Total` by the repeated `Diskon` amount
- **THEN** the sales importer MUST subtract the `Diskon` amount once from the calculated line total
- **AND** the adjusted document total MUST reconcile with source `Total`

#### Scenario: Shipping is applied once
- **WHEN** an invoice group contains a non-zero `Biaya Pengiriman` value repeated on multiple CSV rows
- **THEN** the importer MUST add that shipping amount once to the calculated document total
- **AND** the importer MUST NOT add shipping once per CSV row

### Requirement: Imports prefer fixed document discount amount
The purchase and sales importers SHALL use CSV `Diskon` as the authoritative document-level fixed discount amount and SHALL NOT use CSV `Diskon %` for import calculations.

#### Scenario: Discount percent drifts from exact source total
- **WHEN** an invoice group includes both `Diskon` and `Diskon %`
- **AND** calculating discount from `Diskon %` would not exactly reconcile with source `Total`
- **THEN** the importer MUST use the `Diskon` amount for document total calculation
- **AND** the importer MUST ignore `Diskon %` for math

#### Scenario: Imported document stores fixed discount
- **WHEN** an invoice group imports successfully with a positive document `Diskon`
- **THEN** the created purchase or sale MUST store `discount_amount` equal to the resolved `Diskon` amount
- **AND** the created purchase or sale MUST store `discount_percentage` as zero

### Requirement: Repeated document adjustment fields are validated
The purchase and sales importers SHALL validate repeated document-level adjustment fields within the same invoice and owner group before creating any document or payment row.

#### Scenario: Repeated discount values agree
- **WHEN** rows in the same invoice and owner group repeat the same non-blank `Diskon` value
- **THEN** the importer MUST treat that value as one document-level discount
- **AND** the importer MUST NOT sum repeated `Diskon` values across rows

#### Scenario: Conflicting repeated discount values fail group
- **WHEN** rows in the same invoice and owner group contain conflicting non-blank `Diskon` values
- **THEN** the importer MUST mark the whole invoice group invalid
- **AND** the importer MUST NOT create the purchase or sale document
- **AND** the importer MUST NOT create any payment row for that invoice group

#### Scenario: Conflicting repeated shipping values fail group
- **WHEN** rows in the same invoice and owner group contain conflicting non-blank `Biaya Pengiriman` values
- **THEN** the importer MUST mark the whole invoice group invalid
- **AND** the importer MUST NOT create the purchase or sale document
- **AND** the importer MUST NOT create any payment row for that invoice group

### Requirement: Payment reconciliation uses adjusted document total
The purchase and sales importers SHALL reconcile source total, resolved payment amount, and preferred outstanding balance against the adjusted imported document total. For sales imports where CSV `Total` is authoritative through current-status mapping, the importer SHALL use one canonical adjusted generated sale total for source-total reconciliation, sale header persistence, final settlement validation, and payment row creation.

#### Scenario: Fully paid adjusted purchase imports with payment row
- **WHEN** a purchase CSV invoice group has `Pembayaran` equal to the adjusted document total
- **AND** preferred outstanding balance equals zero
- **THEN** the purchase importer MUST create the purchase with `total_amount`, `paid_amount`, and `due_amount` reconciled to the adjusted document total
- **AND** the importer MUST create exactly one active purchase payment row for the resolved payment amount

#### Scenario: Fully paid adjusted sale imports with payment row
- **WHEN** a sales CSV invoice group has `Pembayaran` equal to the adjusted document total
- **AND** preferred outstanding balance equals zero
- **THEN** the sales importer MUST create the sale with `total_amount`, `paid_amount`, and `due_amount` reconciled to the adjusted document total
- **AND** the importer MUST create exactly one active sale payment row for the resolved payment amount

#### Scenario: Sales canonical total is reused for final settlement validation
- **WHEN** a sales CSV invoice group has high-precision `harga_satuan`, `pajak`, or `Diskon` values whose raw recomputation rounds differently from the source-reconciled generated total
- **AND** the source `Total` is authoritative through current-status mapping
- **THEN** the sales importer MUST persist the source-reconciled canonical generated sale total
- **AND** the final group settlement validation MUST compare paid, deduction, and due amounts against that same canonical generated sale total
- **AND** the importer MUST NOT invalidate the group only because a later raw recomputation has a different cent value within the accepted precision adjustment

#### Scenario: Source total mismatch still fails
- **WHEN** source `Total` does not reconcile with the calculated line total after applying document `Diskon` and `Biaya Pengiriman`
- **THEN** the importer MUST mark the whole invoice group invalid
- **AND** the row error message MUST identify a document total or payment total mismatch

### Requirement: Existing line discount semantics are preserved
The purchase and sales importers SHALL preserve existing `Diskon Per Baris %` behavior and SHALL NOT reinterpret document `Diskon` as a per-item discount.

#### Scenario: Purchase line discount remains line-level
- **WHEN** a purchase row includes `Diskon Per Baris %`
- **THEN** the purchase importer MUST continue applying that value as an existing line-level discount input
- **AND** document-level `Diskon` MUST remain a separate fixed document discount amount

#### Scenario: Sales document discount is separate from line fields
- **WHEN** a sales invoice group includes document `Diskon`
- **THEN** the sales importer MUST apply it as a fixed document discount amount
- **AND** the importer MUST NOT create one product discount per row from document `Diskon`

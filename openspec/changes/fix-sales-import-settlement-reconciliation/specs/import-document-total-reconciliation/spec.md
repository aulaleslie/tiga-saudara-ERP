## MODIFIED Requirements

### Requirement: Imports apply document-level discount and shipping once
The purchase and sales importers SHALL calculate imported document totals by applying document-level `Diskon` once and document-level `Biaya Pengiriman` once per invoice and owner group. For sales source invoices that split into multiple owner groups, the importer SHALL allocate document-level `Diskon` and `Biaya Pengiriman` into two-decimal canonical owner totals whose sum equals the source-invoice adjusted total.

#### Scenario: Discounted purchase invoice reconciles to source total
- **WHEN** a purchase CSV invoice group has line totals that exceed `Total` by the repeated `Diskon` amount
- **THEN** the purchase importer MUST subtract the `Diskon` amount once from the calculated line total
- **AND** the adjusted document total MUST reconcile with source `Total`

#### Scenario: Discounted sales invoice reconciles to source total
- **WHEN** a sales CSV invoice group has line totals that exceed `Total` by the repeated `Diskon` amount
- **THEN** the sales importer MUST subtract the `Diskon` amount once from the calculated line total
- **AND** the adjusted document total MUST reconcile with source `Total`

#### Scenario: Split sales discount allocation preserves source total
- **WHEN** a sales CSV source invoice has rows for more than one product-name owner group
- **AND** repeated document `Diskon` causes fractional pro-rata discount allocations
- **THEN** the sales importer MUST round canonical owner totals to two-decimal money values
- **AND** the importer MUST assign any rounding remainder deterministically so generated owner sale totals sum to the source-invoice adjusted total

#### Scenario: Shipping is applied once
- **WHEN** an invoice group contains a non-zero `Biaya Pengiriman` value repeated on multiple CSV rows
- **THEN** the importer MUST add that shipping amount once to the calculated document total
- **AND** the importer MUST NOT add shipping once per CSV row

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

#### Scenario: Exact single-row sales invoice does not enter precision drift failure
- **WHEN** a single-row sales CSV invoice has `Status Hari Ini` equal to `Lunas`
- **AND** its line DPP plus tax minus document adjustment equals CSV `Total` at two-decimal precision
- **AND** `Pembayaran` equals CSV `Total`
- **THEN** the sales importer MUST import the generated sale as `PAID`
- **AND** the importer MUST NOT mark the row invalid with a precision drift error

#### Scenario: Source total mismatch still fails
- **WHEN** source `Total` does not reconcile with the calculated line total after applying document `Diskon` and `Biaya Pengiriman`
- **THEN** the importer MUST mark the whole invoice group invalid
- **AND** the row error message MUST identify a document total or payment total mismatch

## MODIFIED Requirements

### Requirement: Purchase imports create payment ledger rows
The purchase importer SHALL create active `purchase_payments` rows for future imported purchase documents whose resolved cash payment or non-cash deduction credit is greater than zero, using CSV `Status Hari Ini` and CSV `Total` as authoritative settlement inputs.

#### Scenario: Lunas purchase import creates fully paid ledger rows
- **WHEN** a purchase CSV invoice group imports successfully with `Status Hari Ini` equal to `Lunas`
- **THEN** the created purchase MUST have `payment_status` equal to `PAID`
- **AND** the purchase `paid_amount` MUST equal source CSV `Total`
- **AND** the purchase `due_amount` MUST equal `0.00`
- **AND** the importer MUST create the active purchase payment row or rows needed for active payment rows to sum to the purchase `paid_amount`

#### Scenario: Belum Dibayar purchase import creates no payment
- **WHEN** a purchase CSV invoice group imports successfully with `Status Hari Ini` equal to `Belum Dibayar`
- **THEN** the created purchase MUST have `payment_status` equal to `UNPAID`
- **AND** the purchase `paid_amount` MUST equal `0.00`
- **AND** the purchase `due_amount` MUST equal source CSV `Total`
- **AND** the importer MUST NOT create a `purchase_payments` row for cash payment

#### Scenario: Terbayar Sebagian purchase import creates partial payment
- **WHEN** a purchase CSV invoice group imports successfully with `Status Hari Ini` equal to `Terbayar Sebagian`
- **AND** the source `Pembayaran` is greater than zero
- **THEN** the created purchase MUST have `payment_status` equal to `PARTIAL`
- **AND** the importer MUST create one active cash `purchase_payments` row for the resolved cash payment
- **AND** the purchase `paid_amount` plus `due_amount` MUST reconcile to source CSV `Total`

#### Scenario: Lewat Jatuh Tempo with payment imports as partial
- **WHEN** a purchase CSV invoice group imports successfully with `Status Hari Ini` equal to `Lewat Jatuh Tempo`
- **AND** the source `Pembayaran` is greater than zero
- **THEN** the created purchase MUST have `payment_status` equal to `PARTIAL`
- **AND** overdue display MUST be left to reports or aging views based on due date and outstanding balance

#### Scenario: Lewat Jatuh Tempo without payment imports as unpaid
- **WHEN** a purchase CSV invoice group imports successfully with `Status Hari Ini` equal to `Lewat Jatuh Tempo`
- **AND** the source `Pembayaran` is blank or zero
- **THEN** the created purchase MUST have `payment_status` equal to `UNPAID`
- **AND** overdue display MUST be left to reports or aging views based on due date and outstanding balance

### Requirement: Import payment amount resolution
The purchase and sales importers SHALL resolve imported paid amount from `Pembayaran` when it is present and non-blank, and SHALL fall back to calculated document total minus preferred outstanding balance only when `Pembayaran` is blank or missing. For purchase imports, CSV `Status Hari Ini` and CSV `Total` SHALL override stale or non-reconciling settlement fields according to the purchase status mapping.

#### Scenario: Purchase Lunas status overrides stale payment fields
- **WHEN** a purchase CSV invoice group has `Status Hari Ini` equal to `Lunas`
- **AND** `Pembayaran` is blank, zero, stale, or otherwise does not reconcile with source CSV `Total`
- **THEN** the purchase importer MUST resolve paid amount to source CSV `Total`
- **AND** the purchase importer MUST resolve outstanding balance to `0.00`

#### Scenario: Purchase unpaid status overrides stale payment fields
- **WHEN** a purchase CSV invoice group has `Status Hari Ini` equal to `Belum Dibayar`
- **AND** payment fields do not reconcile with source CSV `Total`
- **THEN** the purchase importer MUST resolve paid amount to `0.00`
- **AND** the purchase importer MUST resolve outstanding balance to source CSV `Total`

#### Scenario: Pembayaran is used when present
- **WHEN** an invoice group includes a non-blank `Pembayaran` value
- **THEN** the importer MUST use that value as the resolved imported paid amount unless a purchase `Status Hari Ini` rule explicitly overrides it
- **AND** the importer MUST validate or derive outstanding balance against the authoritative total for that importer

#### Scenario: Missing Pembayaran falls back to outstanding balance
- **WHEN** an invoice group has no usable `Pembayaran` value
- **THEN** the importer MUST calculate resolved imported paid amount as calculated document total minus preferred outstanding balance unless a purchase `Status Hari Ini` rule explicitly overrides it
- **AND** the importer MUST NOT require the missing `Pembayaran` column to import an otherwise valid invoice group

#### Scenario: Sisa Tagihan Hari Ini is preferred
- **WHEN** an invoice group includes both `Sisa Tagihan Hari Ini` and `Sisa Tagihan`
- **THEN** the importer MUST use `Sisa Tagihan Hari Ini` as the preferred outstanding balance when it reconciles with the authoritative total and resolved settlement components

#### Scenario: Sisa Tagihan is fallback
- **WHEN** an invoice group does not include a usable `Sisa Tagihan Hari Ini` value
- **THEN** the importer MUST use `Sisa Tagihan` as the preferred outstanding balance when it is present and reconciles with the authoritative total and resolved settlement components

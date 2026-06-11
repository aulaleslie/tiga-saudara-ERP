## ADDED Requirements

### Requirement: Sales imports map CSV current payment status
The sales importer SHALL resolve imported sale `paid_amount`, `due_amount`, and `payment_status` from CSV `Status Hari Ini` using the same current-status semantics as purchase import.

#### Scenario: Lunas sales import maps to paid
- **WHEN** a sales CSV invoice has `Status Hari Ini` equal to `Lunas`
- **AND** the CSV `Total` is present
- **THEN** the generated sale document or split sale documents MUST have `payment_status` equal to `PAID`
- **AND** the generated sale document or split sale documents MUST have `due_amount` equal to `0.00`
- **AND** the generated sale active payment rows MUST settle the authoritative generated sale totals

#### Scenario: Belum Dibayar sales import maps to unpaid
- **WHEN** a sales CSV invoice has `Status Hari Ini` equal to `Belum Dibayar` or `Belum Lunas`
- **AND** the CSV `Total` is present
- **THEN** the generated sale document or split sale documents MUST have `payment_status` equal to `UNPAID`
- **AND** the generated sale document or split sale documents MUST have `paid_amount` equal to `0.00`
- **AND** the importer MUST NOT create cash payment rows for those unpaid sale documents

#### Scenario: Terbayar Sebagian sales import maps to partial
- **WHEN** a sales CSV invoice has `Status Hari Ini` equal to `Terbayar Sebagian`
- **AND** the CSV `Pembayaran` is positive and less than the authoritative total after deduction
- **THEN** the generated sale document or split sale documents MUST have `payment_status` equal to `PARTIAL`
- **AND** paid and due amounts MUST be allocated from the authoritative source total

#### Scenario: Lewat Jatuh Tempo with payment maps to partial
- **WHEN** a sales CSV invoice has `Status Hari Ini` equal to `Lewat Jatuh Tempo`
- **AND** the CSV `Pembayaran` is positive
- **THEN** the generated sale document or split sale documents MUST have `payment_status` equal to `PARTIAL`
- **AND** the generated sale documents MUST retain positive `due_amount` unless the payment and deduction fully settle the authoritative total

#### Scenario: Lewat Jatuh Tempo without payment maps to unpaid
- **WHEN** a sales CSV invoice has `Status Hari Ini` equal to `Lewat Jatuh Tempo`
- **AND** the CSV `Pembayaran` is blank, zero, or missing
- **THEN** the generated sale document or split sale documents MUST have `payment_status` equal to `UNPAID`
- **AND** the generated sale documents MUST have `paid_amount` equal to `0.00`

### Requirement: Sales imports use CSV Total as authoritative settlement total
The sales importer SHALL use CSV `Total` as the authoritative settlement total for future sales imports when current payment status mapping is available.

#### Scenario: Sales line total differs from source Total
- **WHEN** a sales CSV invoice has line totals that differ from the repeated CSV `Total`
- **AND** `Status Hari Ini` can derive settlement from the CSV `Total`
- **THEN** the sales importer MUST reconcile generated sale header totals to the CSV `Total`
- **AND** the importer MUST allocate any source-total adjustment across owner sale documents using the same proportional rules as purchase import
- **AND** the generated sale payment rows MUST reconcile to the adjusted generated sale totals

#### Scenario: Over-settled sales payment is clamped
- **WHEN** a sales CSV invoice has `Status Hari Ini` equal to `Terbayar Sebagian` or `Lewat Jatuh Tempo`
- **AND** CSV `Pembayaran` exceeds the authoritative total after deduction
- **THEN** the importer MUST clamp generated paid amounts so no sale has negative `due_amount`
- **AND** active payment rows for each generated sale MUST NOT exceed that sale's `total_amount`

#### Scenario: Sales source Total remains required for authoritative status mapping
- **WHEN** a sales CSV invoice relies on current-status mapping to override stale outstanding values
- **AND** no usable CSV `Total` is present
- **THEN** the importer MUST fall back to existing calculated-total reconciliation or mark the invoice invalid
- **AND** the importer MUST NOT create generated sales whose payment totals cannot be reconciled

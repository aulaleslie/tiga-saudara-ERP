## ADDED Requirements

### Requirement: Sales imports map CSV current payment status
The sales importer SHALL resolve imported sale `paid_amount`, `due_amount`, and `payment_status` from CSV `Status Hari Ini` using the same current-status semantics as purchase import. Generated sale payment status and active payment rows SHALL reconcile to the canonical generated sale total selected during source-invoice reconciliation.

#### Scenario: Lunas sales import maps to paid
- **WHEN** a sales CSV invoice has `Status Hari Ini` equal to `Lunas`
- **AND** the CSV `Total` is present
- **THEN** the generated sale document or split sale documents MUST have `payment_status` equal to `PAID`
- **AND** the generated sale document or split sale documents MUST have `due_amount` equal to `0.00`
- **AND** the generated sale active payment rows MUST settle the authoritative generated sale totals

#### Scenario: Lunas split-owner sales import maps every owner sale to paid
- **WHEN** a sales CSV invoice has `Status Hari Ini` equal to `Lunas`
- **AND** the invoice creates multiple product-name owner sale documents
- **AND** the source invoice settlement fields reconcile to CSV `Total`
- **THEN** every generated owner sale MUST have `payment_status` equal to `PAID`
- **AND** every generated owner sale MUST have `due_amount` equal to `0.00`
- **AND** the sum of generated active payment rows MUST equal the source cash payment plus deduction credit within monetary tolerance

#### Scenario: Lunas sales import with sub-cent source artifacts maps to paid
- **WHEN** a sales CSV invoice has `Status Hari Ini` equal to `Lunas`
- **AND** `Total`, `Pembayaran`, or `Sisa Tagihan Hari Ini` contain sub-cent precision artifacts that round to a fully paid two-decimal money value
- **THEN** the generated sale document or split sale documents MUST have `payment_status` equal to `PAID`
- **AND** the generated sale document or split sale documents MUST have `due_amount` equal to `0.00`
- **AND** generated active payment rows MUST settle the canonical generated sale totals exactly within monetary tolerance

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
The sales importer SHALL use CSV `Total` as the authoritative settlement total for future sales imports when current payment status mapping is available. When source `Total` is authoritative, generated sale totals, paid amounts, due amounts, deduction amounts, final validation, and active payment rows SHALL all use the same canonical owner totals.

#### Scenario: Sales line total differs from source Total
- **WHEN** a sales CSV invoice has line totals that differ from the repeated CSV `Total`
- **AND** `Status Hari Ini` can derive settlement from the CSV `Total`
- **THEN** the sales importer MUST reconcile generated sale header totals to the CSV `Total`
- **AND** the importer MUST allocate any source-total adjustment across owner sale documents using the same proportional rules as purchase import
- **AND** the generated sale payment rows MUST reconcile to the adjusted generated sale totals

#### Scenario: Exact one-cent source-total adjustment is allocated when status mapping is authoritative
- **WHEN** a sales CSV invoice has a calculated adjusted total that differs from the two-decimal CSV `Total` by exactly `0.01`
- **AND** `Status Hari Ini` can derive settlement from the CSV `Total`
- **THEN** the sales importer MUST allocate the one-cent adjustment into the canonical generated sale total
- **AND** generated sale payment rows MUST reconcile to that canonical generated sale total

#### Scenario: Over-settled sales payment is clamped
- **WHEN** a sales CSV invoice has `Status Hari Ini` equal to `Terbayar Sebagian` or `Lewat Jatuh Tempo`
- **AND** CSV `Pembayaran` exceeds the authoritative total after deduction
- **THEN** the importer MUST clamp generated paid amounts so no sale has negative `due_amount`
- **AND** active payment rows for each generated sale MUST NOT exceed that sale's `total_amount`

#### Scenario: Owner settlement does not exceed canonical owner totals
- **WHEN** a paid sales source invoice creates multiple owner sale documents
- **AND** document adjustment or source-total precision allocation creates fractional owner shares
- **THEN** the sales importer MUST allocate cash, deduction, and due amounts against canonical two-decimal owner totals
- **AND** no generated owner sale MAY receive settlement greater than its canonical `total_amount`
- **AND** the importer MUST NOT reject a valid paid source invoice with `settlement exceeds owner document totals` when the source invoice fields reconcile

#### Scenario: Sales source Total remains required for authoritative status mapping
- **WHEN** a sales CSV invoice relies on current-status mapping to override stale outstanding values
- **AND** no usable CSV `Total` is present
- **THEN** the importer MUST fall back to existing calculated-total reconciliation or mark the invoice invalid
- **AND** the importer MUST NOT create generated sales whose payment totals cannot be reconciled

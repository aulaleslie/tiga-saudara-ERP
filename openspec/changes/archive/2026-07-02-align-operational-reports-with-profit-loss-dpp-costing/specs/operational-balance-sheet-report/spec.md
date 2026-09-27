## MODIFIED Requirements

### Requirement: Neraca report calculates asset rows
The system SHALL present asset rows for cash/bank from transaction payments, customer receivables, inventory value, and other operational asset buckets supported by the available data.

#### Scenario: Paid sale increases cash or bank
- **WHEN** an eligible sale has a payment dated on or before the as-of date
- **THEN** the payment amount contributes to the cash/bank asset row

#### Scenario: Unpaid sale creates receivable
- **WHEN** an eligible sale has an outstanding due amount as of the selected date
- **THEN** the outstanding amount from the authoritative current sale document contributes to the customer receivables asset row
- **AND** completed sale return totals are not subtracted again from receivables when the sale document already reflects post-return values.

#### Scenario: Corrected sale after return is not double-subtracted
- **WHEN** an eligible sale has already been corrected for returned quantities and a completed sale return also exists
- **THEN** Neraca calculates customer receivables from the corrected sale document and payments
- **AND** it does not reduce receivables a second time from `sale_returns.total_amount`.

#### Scenario: Inventory value appears as asset
- **WHEN** stock-managed products have inventory value for the active setting
- **THEN** the calculated inventory value contributes to the inventory asset row

### Requirement: Neraca report calculates liability rows
The system SHALL present liability rows for supplier payables, customer return obligations, tax liabilities when available, and other operational liability buckets supported by the available data.

#### Scenario: Unpaid purchase creates payable
- **WHEN** an eligible purchase has an outstanding due amount as of the selected date
- **THEN** the outstanding amount contributes to the supplier payables liability row

#### Scenario: Purchase payment reduces cash or bank
- **WHEN** an eligible purchase payment is dated on or before the as-of date
- **THEN** the payment amount reduces the cash/bank asset row

#### Scenario: Approved expense reduces cash or bank
- **WHEN** an approved, non-archived expense is dated on or before the as-of date
- **THEN** the expense amount reduces the cash/bank asset row

#### Scenario: Sale return refund reduces cash without double-reducing receivable
- **WHEN** a sale return refund payment is dated on or before the as-of date
- **THEN** the refund payment reduces cash or bank according to operational payment movement
- **AND** the report does not also subtract the sale return header from receivables when the current sale document is authoritative.

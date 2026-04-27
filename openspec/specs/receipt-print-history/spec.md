# receipt-print-history Specification

## Purpose
Specifies the tracking and display of print history for POS receipts to provide an audit trail and transaction timing context.

## Requirements

### Requirement: Receipt template displays print history summary
The system SHALL keep print and reprint history available for audit purposes, but the customer-facing receipt template SHALL NOT display the last printer name or latest print/reprint timestamp.

#### Scenario: Print history is recorded for first print
- **WHEN** a receipt is viewed for the first time and logged as PRINT
- **THEN** the system MUST persist the print history entry with print type, user ID, timestamp, and receipt reference
- **AND** the receipt output MUST NOT display a last-printer summary

#### Scenario: Print history is recorded for reprint
- **WHEN** a receipt is reprinted and logged as REPRINT
- **THEN** the system MUST persist the reprint history entry with print type, user ID, timestamp, and receipt reference
- **AND** the receipt output MUST NOT display a last-printer summary

#### Scenario: Print history remains available for audit
- **WHEN** internal code retrieves print history for a receipt or POS transaction
- **THEN** the system MUST provide the total print/reprint count, last printer user information, and latest print/reprint timestamp from persisted logs

#### Scenario: Receipt displays POS transaction time instead of print history time
- **WHEN** a receipt with print history is rendered
- **THEN** the customer-facing receipt date/time MUST come from the POS transaction or completed checkout
- **AND** it MUST NOT come from the latest print/reprint history entry

### Requirement: Print history placement on receipt
Print history information SHALL NOT be rendered as a visible customer-facing section on the receipt template. Any visible footer date/time on the receipt SHALL describe the POS transaction timing rather than print history.

#### Scenario: Print history summary is omitted from receipt body
- **WHEN** rendering a receipt with one or more print/reprint history logs
- **THEN** the receipt body MUST NOT show a print history summary section
- **AND** it MUST NOT show `Terakhir dicetak oleh`, `Printed`, `Last printed by`, or equivalent latest-printer wording

#### Scenario: Audit history remains separate from receipt presentation
- **WHEN** print history logs exist for a receipt
- **THEN** those logs MUST remain persisted and queryable for audit
- **AND** hiding the receipt summary MUST NOT delete or mutate existing print history records

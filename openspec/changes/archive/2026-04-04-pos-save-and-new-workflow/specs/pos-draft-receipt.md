# pos-draft-receipt Specification

## Purpose
Specifies the capability to generate and print a "pro-forma" or draft receipt for the POS Save-and-New workflow, allowing for transaction verification before official payment.

## Requirements

### Requirement: Draft Transaction Receipt Generation
The system SHALL provide an API/route to render a printable receipt view for any non-finalized `PosTransaction`.

#### Scenario: Draft receipt data source
- **WHEN** a request is made for a non-finalized `PosTransaction` (DRAFT or LOADED status)
- **THEN** the system MUST extract line item and total data from the transaction snapshot
- **AND** the receipt SHALL NOT display any payment or change details (since payment is not yet made)

### Requirement: Draft Receipt Identification
Draft receipts SHALL be clearly distinguishable from finalized payment receipts to prevent confusion.

#### Scenario: Branding as DRAFT
- **WHEN** a `PosTransaction` receipt is rendered
- **THEN** it MUST include a clear label such as "STRUK DRAFT" or "PENAWARAN" at the top or bottom of the view
- **AND** the receipt number SHALLL be the `transaction.code` (e.g., TRX-xxxx)

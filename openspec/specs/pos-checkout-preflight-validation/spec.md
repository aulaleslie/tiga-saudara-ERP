# pos-checkout-preflight-validation Specification

## Purpose
This specification defines the requirements for backend preflight validation of POS cart fulfillability and the associated frontend mismatch signaling.

## Requirements

### Requirement: Checkout preflight SHALL validate cart fulfillability before payment flow opens
The POS system SHALL execute a backend preflight validation when cashier clicks `Pilih Pembayaran` and MUST only open staged payment modal when preflight returns success.

#### Scenario: Preflight passes and payment flow opens
- **WHEN** cashier clicks `Pilih Pembayaran` and all cart lines are valid for serial and stock fulfillment
- **THEN** system SHALL return a successful preflight response
- **AND** staged payment modal SHALL open using the existing cart token and grand total

#### Scenario: Preflight fails and payment flow remains blocked
- **WHEN** cashier clicks `Pilih Pembayaran` and one or more cart lines fail serial/stock validation
- **THEN** system SHALL return a preflight failure response
- **AND** staged payment modal MUST NOT open

### Requirement: Preflight failure response SHALL include actionable mismatch details
For preflight failures, backend SHALL return a structured payload that UI can render into a mismatch dialog without parsing human text.

#### Scenario: Stock mismatch detail is returned
- **WHEN** a cart line requests quantity greater than currently fulfillable stock
- **THEN** response SHALL include `code`, `message`, and `details.unfulfilled_lines[]`
- **AND** each failing line entry SHALL include `line_index`, `product_id`, and machine-readable `reason_code`

#### Scenario: Serial mismatch detail is returned
- **WHEN** assigned serial count or assigned serial availability is invalid for a serial-required line
- **THEN** response SHALL include a failing line entry for that line
- **AND** the entry SHALL include a reason code suitable for UI-specific messaging

### Requirement: POS mismatch dialog SHALL return cashier to cart context
The POS UI SHALL show a dedicated mismatch dialog for preflight failures and SHALL keep cashier in POS cart context after dialog close.

#### Scenario: Dialog close returns to POS without staged modal
- **WHEN** mismatch dialog is shown from preflight failure and cashier closes the dialog
- **THEN** focus remains on POS page/cart context
- **AND** no staged payment modal is opened automatically

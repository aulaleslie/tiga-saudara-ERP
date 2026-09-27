## MODIFIED Requirements

### Requirement: Preflight failure response SHALL include actionable mismatch details
For preflight failures, backend SHALL return a structured payload that UI can render into a mismatch dialog without parsing human text. UI consumption of this contract MUST treat `requested_qty` and `allocated_qty` as canonical quantity diagnostics and MUST compute shortage deterministically when a dedicated shortage field is absent.

#### Scenario: Stock mismatch detail is returned
- **WHEN** a cart line requests quantity greater than currently fulfillable stock
- **THEN** response SHALL include `code`, `message`, and `details.unfulfilled_lines[]`
- **AND** each failing line entry SHALL include `line_index`, `product_id`, and machine-readable `reason_code`

#### Scenario: Stock mismatch line diagnostics include canonical quantities
- **WHEN** response contains a stock-unfulfilled line entry
- **THEN** each line entry SHALL include `requested_qty` and `allocated_qty`
- **AND** UI SHALL be able to derive `shortage = max(requested_qty - allocated_qty, 0)` without relying on extra fields

#### Scenario: Serial mismatch detail is returned
- **WHEN** assigned serial count or assigned serial availability is invalid for a serial-required line
- **THEN** response SHALL include a failing line entry for that line
- **AND** the entry SHALL include a reason code suitable for UI-specific messaging

### Requirement: POS mismatch dialog SHALL return cashier to cart context
The POS UI SHALL show a dedicated mismatch dialog for preflight failures and SHALL keep cashier in POS cart context after dialog close. Dialog line rendering MUST remain actionable even when backend omits optional product display fields by falling back from `product_name` to stable identifiers (`product_code` or `product_id`).

#### Scenario: Dialog close returns to POS without staged modal
- **WHEN** mismatch dialog is shown from preflight failure and cashier closes the dialog
- **THEN** focus remains on POS page/cart context
- **AND** no staged payment modal is opened automatically

#### Scenario: Dialog renders line identity with fallback identifiers
- **WHEN** a mismatch line has no `product_name` but includes `product_code` and/or `product_id`
- **THEN** dialog SHALL still display an identifiable product label for cashier correction
- **AND** line quantities SHALL remain visible for requested, allocated, and shortage context

## MODIFIED Requirements

### Requirement: Checkout preflight SHALL validate cart fulfillability before payment flow opens
The POS system SHALL execute a backend preflight validation when cashier clicks `Pilih Pembayaran` and MUST only open staged payment modal when preflight returns success. A restored cart line classified as non-stock-managed MUST be excluded from stock-availability validation, while restored stock-managed lines MUST remain subject to existing serial and stock fulfillment validation.

#### Scenario: Preflight passes and payment flow opens
- **WHEN** cashier clicks `Pilih Pembayaran` and all cart lines are valid for serial and stock fulfillment
- **THEN** system SHALL return a successful preflight response
- **AND** staged payment modal SHALL open using the existing cart token and grand total

#### Scenario: Preflight fails and payment flow remains blocked
- **WHEN** cashier clicks `Pilih Pembayaran` and one or more cart lines fail serial/stock validation
- **THEN** system SHALL return a preflight failure response
- **AND** staged payment modal MUST NOT open

#### Scenario: Loaded non-stock line does not create a false stock mismatch
- **WHEN** cashier clicks `Pilih Pembayaran` for a loaded draft whose service line is classified as non-stock-managed and has no product-stock record
- **THEN** preflight MUST NOT return a stock-unavailable error for that service line
- **AND** the staged payment modal SHALL be permitted to open when all stock-managed lines are fulfillable

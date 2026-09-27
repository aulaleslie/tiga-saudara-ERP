## ADDED Requirements

### Requirement: Opening float validation for terminal sessions

The system SHALL validate opening float (Saldo Awal) when opening a POS session with a terminal selection. When `require_opening_float` policy flag is enabled, opening float MUST be positive (> 0). The opening float is NOT validated against the terminal policy's `cash_threshold` setting—that threshold is reserved for monitoring and informational purposes only.

#### Scenario: Valid opening float with terminal selected

- **WHEN** a cashier initiates session open with `terminal_id` set and `opening_float_total > 0`
- **AND** the terminal policy has `require_opening_float = true`
- **THEN** the session SHALL be created successfully
- **AND** the opening float SHALL be recorded in `opening_float_total` field

#### Scenario: Opening float below terminal cash threshold

- **WHEN** a cashier initiates session open with `opening_float_total` less than the terminal policy's `cash_threshold`
- **AND** the opening float is positive (> 0)
- **AND** the terminal policy has `require_opening_float = true`
- **THEN** the session SHALL be created successfully (no validation gate on cash_threshold)
- **AND** the cash_threshold field remains available for monitoring services to flag this session

#### Scenario: Zero or negative opening float with terminal selected

- **WHEN** a cashier initiates session open with `terminal_id` set and `opening_float_total <= 0`
- **THEN** the session creation SHALL be rejected
- **AND** the system SHALL return an error: "Opening float total must be greater than zero."

#### Scenario: Terminal not selected - opening float not required

- **WHEN** a cashier initiates session open with `terminal_id` null/empty
- **THEN** the session SHALL be created regardless of `opening_float_total` value
- **AND** the opening float validation SHALL NOT apply

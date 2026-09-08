## MODIFIED Requirements

### Requirement: Save and Checkout Operations MUST Block When Serial Count Does Not Match Quantity
The POS checkout flow SHALL validate that assigned serial count equals the sale quantity for all serial-required items before allowing checkout. Save Draft SHALL NOT be gated by serial-quantity match — a cart with incomplete or mismatched serial assignments MAY be saved as a draft, deferring serial completion to a later editing session.

#### Scenario: Save Draft is allowed when serials are fewer than quantity
- **WHEN** a cart line has `serial_number_required=true` with `qty=3` and `assigned_serials=[SN-001]`
- **THEN** the Save Draft button MUST remain enabled (subject to other existing non-serial save conditions)
- **AND** the Checkout button MUST be disabled

#### Scenario: Save Draft is allowed when serials exceed quantity
- **WHEN** a cart line has `serial_number_required=true` with `qty=1` and `assigned_serials=[SN-001, SN-002]`
- **THEN** the Save Draft button MUST remain enabled (subject to other existing non-serial save conditions)
- **AND** the Checkout button MUST be disabled

#### Scenario: Checkout is blocked when serials exceed quantity
- **WHEN** a cart line has `serial_number_required=true` with `qty=1` and `assigned_serials=[SN-001, SN-002]`
- **THEN** the Checkout button MUST be disabled
- **AND** an error message MUST be displayed indicating the mismatch

#### Scenario: Checkout is blocked when quantity exceeds assigned serials
- **WHEN** a cart line has `serial_number_required=true` with `qty=3` and `assigned_serials=[SN-001]`
- **THEN** the Checkout button MUST be disabled

#### Scenario: Checkout is enabled when counts match
- **WHEN** a cart line has `serial_number_required=true` with `qty=2` and `assigned_serials=[SN-001, SN-002]`
- **THEN** the Checkout button MUST be enabled (assuming other guards pass)
- **AND** the Save Draft button MUST be enabled (assuming other guards pass)

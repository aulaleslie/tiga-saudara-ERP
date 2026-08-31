## MODIFIED Requirements

### Requirement: Blended-line storage with authoritative line total

The system SHALL store a packed line as a single line whose `line_total` is authoritative (integer minor units) and whose `unit_price` is a display-only blended value equal to `line_total / qty`. For an automatically priced packed row recalculated by user interaction, the system SHALL first compute the internal packed total, then round the final tax-inclusive customer-facing line total using the effective business configuration. Cart totals SHALL derive from the authoritative line total so displayed totals tie out exactly, independent of blended-price rounding. Explicit unit-price and line-total overrides SHALL bypass automatic row-total rounding.

#### Scenario: Totals derive from authoritative line total
- **WHEN** a packed line has an authoritative line total of 255000 and quantity 6 with a blended unit price of 42500
- **THEN** the cart subtotal contribution for that line is exactly 255000

#### Scenario: Blended unit price that does not divide evenly does not corrupt the total
- **WHEN** a packed line has an authoritative line total of 100000 and quantity 3 with a blended unit price that rounds to 33333.33
- **THEN** the cart subtotal contribution for that line is exactly 100000, not 99999.99

#### Scenario: Automatic packed result rounds only after packing
- **WHEN** packing rules produce an automatic final tax-inclusive line amount of `78999.00` and the business increment is `100.00`
- **THEN** the authoritative customer-facing packed line total SHALL be `79000.00`
- **AND** the system SHALL NOT round individual full-box groups or loose-unit remainder charges

#### Scenario: Overridden packed total remains exact
- **WHEN** an authorized user explicitly overrides a packed row total to `78949.00`
- **THEN** the authoritative packed row total SHALL remain `78949.00`
- **AND** automatic row-total rounding SHALL NOT apply


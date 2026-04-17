## ADDED Requirements

### Requirement: Validated serial allocations SHALL be retained through final posting
When finalize pre-check validates a serial-tracked checkout line using assigned serial context, the resulting allocation SHALL remain available to the final posting adapter after any split planning step. A line that passed serial stock validation MUST NOT later fail solely because its parent allocation was omitted from grouped posting context.

#### Scenario: Valid serial allocation posts after split planning
- **WHEN** a serial-tracked checkout line has assigned serials that are active, allowed, and backed by sufficient stock bucket quantity
- **AND** split posting is enabled
- **THEN** final posting receives a non-empty allocation for that serial line
- **AND** checkout does not fail with `STOCK_UNAVAILABLE` due to missing parent allocation

#### Scenario: Serial lifecycle updates use grouped allocation serials
- **WHEN** split posting finalizes a serial-tracked checkout line
- **THEN** each assigned serial posted in a grouped line is marked sold
- **AND** each sold serial is linked to the dispatch detail created for its grouped line

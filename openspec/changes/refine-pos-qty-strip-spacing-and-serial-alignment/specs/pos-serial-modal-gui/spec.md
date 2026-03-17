## MODIFIED Requirements

### Requirement: Improved Serial Chip Layout
Serial-required cart rows MUST present serial management UI in a centered stacked qty-cell layout so quantity and serial actions remain visually grouped and scannable.

#### Scenario: Center-aligned stacked serial qty cell
- **WHEN** a serial-required cart row is rendered
- **THEN** the qty cell MUST show three centered layers in order: compact spinner row, serial-action row, and serial-chip row.

#### Scenario: Wrapped serial chips remain centered under serial controls
- **WHEN** multiple serial chips are attached to a serial-required line
- **THEN** chips MUST wrap within the qty cell and remain center-aligned beneath the serial-action row.

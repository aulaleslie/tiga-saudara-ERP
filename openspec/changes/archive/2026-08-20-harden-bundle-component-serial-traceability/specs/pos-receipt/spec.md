## ADDED Requirements

### Requirement: Completed receipts SHALL identify bundle-component serials
A completed POS receipt and reprint SHALL render persisted component serial assignments beneath the bundle component to which they belong, without consulting the live bundle definition or current serial location/status.

#### Scenario: Receipt contains serialized parent and component
- **WHEN** a completed bundled transaction contains parent serials and component serials
- **THEN** the receipt SHALL distinguish parent serials from component serials
- **AND** each component serial SHALL appear with its persisted component name or code

#### Scenario: Historical bundle definition changes
- **WHEN** a completed receipt is reprinted after the live bundle or component changes or is deleted
- **THEN** the receipt SHALL retain the originally posted component-to-serial association


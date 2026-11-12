# Spec Delta

## ADDED Requirements

### Requirement: Final serial location authorization SHALL use the originating POS business
Final locked posting SHALL authorize each parent and bundle-component serial's current source location against the originating POS business's enabled sales locations, including after split posting switches to a source owner. A source owner's own sales-location configuration MUST NOT replace this authorization. Sale ownership and inventory effects SHALL continue to use the actual source owner and location.

#### Scenario: Parent serial source is enabled for POS business but disabled for source owner
- **WHEN** a sellable parent serial is in a location enabled for the originating POS business and disabled for the source owner's own POS
- **AND** all other checkout validations pass
- **THEN** split checkout SHALL succeed
- **AND** the generated sale, dispatch, inventory movement, and serial dispatch linkage SHALL retain the actual source owner and location

#### Scenario: Bundle component source is enabled only for originating POS business
- **WHEN** a sellable serialized bundle component is in a location enabled for the originating POS business and disabled for the source owner's own POS
- **AND** all other checkout validations pass
- **THEN** split checkout SHALL succeed using the originating POS business's location authorization
- **AND** the component's dispatch, inventory movement, serial transition, and serial history SHALL retain the actual source owner and location

#### Scenario: Source-owner permission cannot authorize a forbidden POS location
- **WHEN** a serial's current location is disabled for the originating POS business but enabled for the source owner's own POS
- **THEN** checkout SHALL reject that serial
- **AND** no partial checkout posting effects SHALL remain

#### Scenario: Serial moves outside the POS business's allowed locations before locked posting
- **WHEN** an assigned parent or component serial moves to a location outside the originating POS business's allowed locations before final locked posting
- **THEN** final posting SHALL reject that serial and roll back atomically
- **AND** source-owner location permissions SHALL NOT override the rejection

#### Scenario: Inline posting without separate origin context
- **WHEN** inline posting receives no separate originating POS business identity
- **THEN** final serial location authorization SHALL use the posting context's existing business identity
- **AND** existing sellability, reservation, and dispatch checks SHALL remain enforced

## ADDED Requirements

### Requirement: Serial stock bucket determined by serial tax_id
The stock resolver SHALL use each serial number's own `tax_id` field to determine which stock bucket (`quantity_tax` or `quantity_non_tax`) to validate against. The cart line's `tax_id` SHALL NOT influence stock bucket selection for serial products.

#### Scenario: Serial with tax_id at PKP location
- **WHEN** a serial has `tax_id=2` and is at a location where `quantity_tax >= 1`
- **THEN** the resolver SHALL group it into a TAX bucket allocation and validate against `quantity_tax`

#### Scenario: Serial with null tax_id at non-PKP location
- **WHEN** a serial has `tax_id=NULL` and is at a location where `quantity_non_tax >= 1`
- **THEN** the resolver SHALL group it into a NON_TAX bucket allocation and validate against `quantity_non_tax`, regardless of the cart line's `tax_id`

#### Scenario: Mixed serials across locations with different tax statuses
- **WHEN** a cart line has 6 serials: 1 with `tax_id=2` at location 1, and 5 with `tax_id=NULL` at locations 2-6
- **AND** location 1 has `quantity_tax >= 1` and locations 2-6 each have `quantity_non_tax >= 1`
- **THEN** the resolver SHALL produce separate allocation groups: one TAX group for location 1 and five NON_TAX groups for locations 2-6
- **AND** all groups SHALL pass validation

#### Scenario: Serial with null tax_id fails when no non-tax stock at serial location
- **WHEN** a serial has `tax_id=NULL` at a location where `quantity_non_tax = 0`
- **THEN** the resolver SHALL return reason code `SERIAL_NON_TAX_STOCK_UNAVAILABLE`

#### Scenario: Serial with tax_id fails when no tax stock at serial location
- **WHEN** a serial has `tax_id=2` at a location where `quantity_tax = 0`
- **THEN** the resolver SHALL return reason code `SERIAL_TAX_STOCK_UNAVAILABLE`

### Requirement: Serial grouping key uses serial-derived tax bucket
The allocation grouping key for serial lines SHALL be constructed as `{source_setting_id}:{location_id}:TAX:{tax_id}` when the serial has a `tax_id`, or `{source_setting_id}:{location_id}:NON_TAX` when the serial has no `tax_id`. This ensures serials from the same location but different buckets are grouped and validated separately.

#### Scenario: Two serials at same location with different tax statuses
- **WHEN** two serials are at location 1, one with `tax_id=2` and one with `tax_id=NULL`
- **THEN** the resolver SHALL produce two separate allocation groups for location 1: one TAX and one NON_TAX
- **AND** each group SHALL validate against its respective bucket independently

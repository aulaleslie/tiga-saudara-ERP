## Why

The sales-location configuration page mixes enabled POS locations with disabled or unassigned locations from other businesses in one sortable list. Because unassigned locations have no priority position, they can appear before usable locations and can be moved even though saving their order is invalid.

## What Changes

- Separate the enabled POS sale-location priority list from disabled or not-yet-enabled locations available to the current business.
- Restrict priority ordering and reorder submissions to enabled, assigned locations only.
- Keep disabled or unassigned foreign locations out of the priority list until an authorized user enables them; enabling assigns the next available priority position.
- Ensure an owned location is backed by an enabled sale-location assignment rather than only being treated as enabled in the display.
- Add regression coverage for ordering, enablement, validation, and cache-consistent POS resolution.

## Capabilities

### New Capabilities

- `sales-location-priority-configuration`: Defines the enabled-only POS priority list, the disabled enable-first list, and valid reorder behavior.

### Modified Capabilities

- `pos-sale-location-onboarding`: Clarifies the owner-assignment invariant and enabled-only priority ordering.

## Impact

- Affects the Setting sale-location configuration controller, Blade page, `SettingSaleLocation` lifecycle behavior, and sale-location configuration tests.
- Preserves the existing `SalesLocationResolver` contract: it resolves only enabled locations in deterministic priority order.

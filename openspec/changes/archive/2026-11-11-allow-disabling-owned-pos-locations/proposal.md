# Proposal

## Why

Businesses cannot exclude their own locations from POS: the interface and controller prohibit disabling them, and page-load repair re-enables disabled assignments. Ownership should provide an enabled default while allowing an explicit choice to persist.

## What Changes

- Allow authorized users to disable and re-enable owned locations using the existing configuration controls.
- Display disabled owned locations in the available list; clear priority on disable and append on re-enable.
- Preserve explicit disabled choices during configuration repair and ownership transfer. Create missing owner assignments enabled by default.
- Allow zero enabled locations, with a clear POS configuration error preventing checkout.
- Preserve the shared enabled list for POS, Price Point browsing, and Sales cost-fallback ranking; do not globally deactivate locations or alter other businesses' choices.
- Retain existing enabled data and new-location defaults without a schema or data migration.
- Use focused feature and regression verification.

## Capabilities

### New Capabilities

None.

### Modified Capabilities

- `pos-sale-location-onboarding`: Optional owner enablement, persistent explicit choices, ownership-transfer defaults, available-list behavior, and empty-configuration handling.

## Impact

Changes are expected in `SaleLocationConfigurationController`, its Blade view, `SettingSaleLocation::repairMissingOwnedAssignments`, and `Location` ownership lifecycle handling. Existing resolver/cache behavior remains authoritative. Tests must replace the owned-disable prohibition and cover lifecycle preservation, ordering, business isolation, and empty POS configuration. Existing onboarding wording that always enables an existing assignment is explicitly superseded. No new dependency, permission, or database field is needed.

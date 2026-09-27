## Why

A recent migration altered the `position` column in `setting_sale_locations` to be `NOT NULL` (with no default database value). However, when a location is newly enabled via `/sales-location-configurations`, `SaleLocationConfigurationController@toggle` uses `SettingSaleLocation::updateOrCreate()` which does not explicitly supply a `position`. This causes a `General error: 1364 Field 'position' doesn't have a default value` SQL exception, preventing users from enabling sale locations. This fix resolves the issue so locations can be enabled again.

## What Changes

- Automatically assign the next sequential `position` to newly created `SettingSaleLocation` records using an Eloquent `creating` event.
- Ensure the position defaults gracefully when using `updateOrCreate` without explicitly specifying it.

## Capabilities

### New Capabilities

### Modified Capabilities
- `pos-sale-location-onboarding`: Ensure location enablement handles position assignment correctly.

## Impact

- `Modules\Setting\Entities\SettingSaleLocation`: Will have an Eloquent `creating` event.
- `/sales-location-configurations`: Location toggling will work properly again without raising SQL exceptions.
